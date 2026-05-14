<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleInterceptionSmartIvr\Lib;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Common\Providers\CDRDatabaseProvider;
use Modules\ModuleInterceptionSmartIvr\bin\ConnectorDB;

class HistoryParser
{
    public const LIMIT_CDR = 1000;
    public const TYPE_OUTGOING = 'OUTGOING';
    public const TYPE_ALL = '';
    public const TYPE_INNER = 'INNER';
    public const TYPE_INCOMING = 'INCOMING';

    /**
     * Заполнение кэш истории звонков. Кто последний говорил с клиентом.
     * @param $offset
     * @param $referenceDate
     * @return void
     */
    public static function getHistoryData(int &$offset = 1, string $referenceDate = '2000-00-00 00:00:00.0'):array
    {
        $maxLength = intval(PbxSettings::getValueByKey('PBXInternalExtensionLength'));
        $add_query                     = [
            'linkedid IN ({linkedid:array})',
            'bind'    => [
                'linkedid' => null,
            ],
            'order'   => 'id',
        ];
        $filter                        = [
            'id>:id: AND start>:referenceDate:',
            'bind'    => [
                'id'  => $offset,
                'referenceDate' => $referenceDate
            ],
            'group'   => 'linkedid',
            'columns' => 'linkedid',
            'limit'   => self::LIMIT_CDR,
            'add_pack_query' => $add_query,
        ];

        $cdrData = CDRDatabaseProvider::getCdr($filter);
        $resultRows = [];
        $innerId = [];
        foreach ($cdrData as $cdr){
            $offset = (int)$cdr['id'];
            $srcInner = self::isInnerCdr($cdr, 'src', $maxLength);
            $dstInner = self::isInnerCdr($cdr, 'dst', $maxLength);
            if(($srcInner && $dstInner) || in_array($cdr['linkedid'], $innerId,true)){
                if(!isset($resultRows[$cdr['linkedid']])){
                    // Это первая CDR для звонка.
                    $innerId[] = $cdr['linkedid'];
                }
                continue;
            }
            self::fillFirstCdr($cdr, $srcInner, $dstInner,$resultRows);

            // Это последующие строки телефонного звонка.
            if($resultRows[$cdr['linkedid']]['out']){
                $userField  = 'src';
            }else{
                $userField  = 'dst';
                if(self::isNoAnswerCdr($cdr, $resultRows, 'src')){
                    continue;
                }
            }
            if(self::isInnerCdr($cdr, $userField, $maxLength)){
                $resultRows[$cdr['linkedid']]['innerNum'] = $cdr["{$userField}_num"];
            }
        }

        return self::prepareFinalData($resultRows);
    }

    /**
     * Формирует итоговую таблицу CDR.
     * @param $resultRows
     * @return array
     */
    private static function prepareFinalData($resultRows):array
    {
        $results = [];
        foreach ($resultRows as $id => $row){
            if(!isset($row['innerNum'])){
                unset($resultRows[$id]);
                continue;
            }
            unset($resultRows[$id]['out']);
            $phoneId = ConnectorDB::getPhoneIndex($row['number']);
            $results[$phoneId] = $resultRows[$id];
        }
        return $results;
    }

    /**
     * Определяет, является ли номер в CDR внутренним.
     * @param $cdr
     * @param $fieldName
     * @param int $maxLength
     * @return bool
     */
    private static function isInnerCdr($cdr, $fieldName, int $maxLength=5):bool
    {
        if(strlen($cdr["{$fieldName}_num"]) <= $maxLength){
            // is inner number;
            return true;
        }
        return is_numeric($cdr["{$fieldName}_num"]) && strpos($cdr["{$fieldName}_chan"], "/{$cdr["{$fieldName}_num"]}-") !== false;
    }

    /**
     * Является ли вызов Не отвеченным.
     * @param $cdr
     * @param $resultRows
     * @param $clientField
     * @return bool
     */
    private static  function isNoAnswerCdr($cdr, $resultRows, $clientField):bool
    {
        $cdrNum    = ConnectorDB::getPhoneIndex($cdr["{$clientField}_num"]);
        $clientNum = ConnectorDB::getPhoneIndex($resultRows[$cdr['linkedid']]['number']);
        return empty($cdr['answer']) || $cdr['billsec'] === '0' || $cdrNum !== $clientNum;
    }

    /**
     * Заполнение первой строка CDR.
     * @param $cdr
     * @param $srcInner
     * @param $dstInner
     * @param $resultRows
     * @return void
     */
    private static  function fillFirstCdr($cdr, $srcInner, $dstInner, &$resultRows):void
    {
        if(!isset($resultRows[$cdr['linkedid']])){
            $outCall = false;
            if(stripos($cdr['src_chan'], 'local/') !== false && stripos( $cdr['dst_chan'], 'pjsip/sip') !== false){
                // Автодиалер звонки.
                $srcInner = true;
            }
            if($srcInner){
                // Исходящий вызов;
                $number  = $cdr['dst_num'];
                $outCall = true;
            }else{
                $number = $cdr['src_num'];
            }

            if($srcInner && $dstInner){
                $type = self::TYPE_INNER;
            }elseif(!$srcInner){
                $type = self::TYPE_INCOMING;
            }else{
                $type = self::TYPE_OUTGOING;
            }
            // Это первая строка нового звонка.
            $resultRows[$cdr['linkedid']] = [
                'date'    => $cdr['start'],
                'number'  => $number,
                'out'     => $outCall,
                'linkedid'=> $cdr['linkedid'],
                'type'    => $type,
            ];
        }

    }

}