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

namespace Modules\ModuleInterceptionSmartIvr\bin;

use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleInterceptionSmartIvr\Lib\HistoryParser;
use Modules\ModuleInterceptionSmartIvr\Lib\Logger;
use Exception;
use Modules\ModuleInterceptionSmartIvr\Models\CdrResponsible;
use Modules\ModuleInterceptionSmartIvr\Models\CrmUsers;
use Modules\ModuleInterceptionSmartIvr\Models\ModuleInterceptionSmartIvr;
use Modules\ModuleInterceptionSmartIvr\Models\Responsibles;
use DateTime;

require_once 'Globals.php';

class ConnectorDB extends WorkerBase
{
    private Logger $logger;

    public const SAVE_USERS   = 'saveCrmUsers';
    public const GET_SETTINGS   = 'getSettings';
    public const SAVE_SETTINGS   = 'saveSettings';
    public const SAVE_RESPONSIBLE   = 'saveResponsibleData';
    public const GET_RESPONSIBLE   = 'getResponsibleByPhone';
    public const DELETE_RESPONSIBLE   = 'deleteResponsibleData';
    public const DELETE_USERS = 'deleteCrmUsers';

    public int $cdrOffset = 1;
    public string $cdrCountDays = '';
    public string $referenceDate = '';
    public bool $disableIvr = true;
    public bool $simpleMode = true;

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger   = new Logger('ConnectorDB', 'ModuleInterceptionSmartIvr');
        $this->logger->writeInfo('Starting...');

        $this->updateSettings();

        $beanstalk      = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while (true) {
            $beanstalk->wait();
            $this->logger->rotate();
        }
    }

    /**
     * Получение настроек модуля.
     * @return void
     */
    public function updateSettings(int $newCdrOffset=0):void
    {
        $settings = ModuleInterceptionSmartIvr::findFirst();
        if(!$settings){
            $settings = new ModuleInterceptionSmartIvr();
        }
        if($newCdrOffset > 0){
            $settings->cdrOffset = $newCdrOffset;
            $settings->save();
        }
        if(empty($settings->cdrOffset) || empty($settings->referenceDate)){
            $settings->cdrOffset = 1;
            $settings->referenceDate = date("Y-m-d H:i:s.0", strtotime("-60 days"));
            $settings->cdrCountDays = 60;
            $settings->save();
        }
        $this->cdrOffset     = (int)$settings->cdrOffset;
        $this->referenceDate = $settings->referenceDate;

        // cdrCountDays хранит глубину анализа истории в ЧАСАХ (имя поля историческое).
        // Здесь вычисляем дату-границу: записи cdr_responsible старше неё считаются неактуальными.
        $date = new DateTime();
        $date->modify('-'.(int)$settings->cdrCountDays.' hours');
        $this->cdrCountDays = $date->format('Y-m-d H:i:s');
        $this->disableIvr    = intval($settings->disableIvr) === 1;
        $this->simpleMode    = intval($settings->simpleMode) === 1;
    }

    /**
     * Ответ на запрос состояния сервиса.
     * @param BeanstalkClient $message
     * @return void
     */
    public function pingCallBack(BeanstalkClient $message): void
    {
        parent::pingCallBack($message);
        $this->updateSettings();
        $this->syncCdrData();
    }

    /**
     * Получение запросов на идентификацию номера телефона.
     * @param $tube
     * @return void
     */
    public function onEvents($tube): void
    {
        try {
            $data = json_decode($tube->getBody(), true, 512, JSON_THROW_ON_ERROR);
        }catch (Exception $e){
            return;
        }
        if($data['action'] === 'invoke'){
            $res_data = [];
            $funcName = $data['function']??'';
            if(method_exists($this, $funcName)){
                if(count($data['args']) === 0){
                    $res_data = $this->$funcName();
                }else{
                    $res_data = $this->$funcName(...$data['args']??[]);
                }
                $res_data = serialize($res_data);
            }else{
                $this->logger->writeError($data);
            }
            if(isset($data['need-ret'])){
                $tube->reply($res_data);
            }
        }
    }

    /**
     * Сохрание данных пользователей CRM в базе данных.
     * @param $users
     * @return PBXApiResult
     */
    public function saveCrmUsers($users):PBXApiResult
    {
        $res = new PBXApiResult();
        $res->success = true;
        $this->db->begin();
        foreach ($users as $user){
            $userDb = CrmUsers::findFirst("crmId='{$user['crmId']}'");
            if(!$userDb){
                $userDb = new CrmUsers();
            }
            foreach ($userDb->toArray() as $key => $value){
                if(isset($user[$key])){
                    $userDb->writeAttribute($key, $user[$key]);
                }
            }
            $result = $userDb->save();
            if(!$result){
                $res->success = false;
                $res->messages[] = [
                    'crmId' => $user['crmId'],
                    'error' => $userDb->getMessages()
                ];
            }else{
                $res->data[] = [
                    'crmId' => $user['crmId'],
                    'id' => $userDb->id
                ] ;
            }
        }
        $this->db->commit();
        return $res;
    }

    /**
     * Удаление пользователей по ID.
     * @param $ids
     * @return PBXApiResult
     */
    public function deleteCrmUsers($ids):PBXApiResult
    {
        $res = new PBXApiResult();
        $this->db->begin();
        $res->success = true;
        foreach ($ids as $id) {
            /** @var CrmUsers $userDb */
            $userDb = CrmUsers::findFirst("crmId='{$id['crmId']}'");
            if(!$userDb){
                $res->data[] = [
                    'crmId' => $id['crmId'],
                ];
                continue;
            }
            $result = $userDb->delete();
            if($result){
                $res->data[] = [
                    'crmId' => $id['crmId'],
                ] ;
            }else{
                $res->success = false;
                $res->messages[] = [
                    'crmId' => $id['crmId'],
                    'error' => $userDb->getMessages()
                ];
            }
        }
        $this->db->commit();
        return $res;
    }

    /**
     * Метод возвращает информцию по номеру телефона.
     * @param $phone
     * @param $typeCall
     * @return PBXApiResult
     */
    public function getResponsibleByPhone($phone, string $typeCall = ''):PBXApiResult
    {
        $res = new PBXApiResult();
        $res->success = true;
        $manager = $this->di->get('modelsManager');
        $res->data = [];
        if($this->disableIvr === false && $this->simpleMode === false){
            $parameters = [
                'models'     => [
                    'Responsibles' => Responsibles::class,
                ],
                'conditions' => 'Responsibles.phoneId = :phoneId:',
                'bind' => [
                    'phoneId'        => self::getPhoneIndex($phone),
                ],
                'columns'    => [
                    'clientName' => 'Responsibles.name',
                    'typeCall' => '',
                    'name' => 'CrmUsers.name',
                    'number' => 'CrmUsers.number',
                    'position' => 'CrmUsers.position',
                    'source' => '"crm"'
                ],
                'joins'      => [
                    'CrmUsers' => [
                        0 => CrmUsers::class,
                        1 => 'Responsibles.employeeId = CrmUsers.crmId',
                        2 => 'CrmUsers',
                        3 => 'LEFT',
                    ],
                ],
            ];
            $res->data = $manager->createBuilder($parameters)->getQuery()->execute()->toArray();
            if(empty($res->data)){
                $parameters = [
                    'models'     => [
                        'CdrResponsible' => CdrResponsible::class,
                    ],
                    'conditions' => 'CdrResponsible.phoneId = :phoneId: AND date > :cdrCountDays: AND CdrResponsible.typeCall = :typeCall:',
                    'bind' => [
                        'phoneId'        => self::getPhoneIndex($phone),
                        'cdrCountDays'   => $this->cdrCountDays,
                        'typeCall'       => $typeCall,
                    ],
                    'columns'    => [
                        'clientName' => 'CdrResponsible.number',
                        'typeCall' => 'CdrResponsible.typeCall',
                        'name' => 'CrmUsers.name',
                        'number' => 'CrmUsers.number',
                        'position' => 'CrmUsers.position',
                        'source' => '"cdr"'
                    ],
                    'joins'      => [
                        'CrmUsers' => [
                            0 => CrmUsers::class,
                            1 => 'CdrResponsible.innerNum = CrmUsers.number',
                            2 => 'CrmUsers',
                            3 => 'LEFT',
                        ],
                    ],
                ];
                $res->data = $manager->createBuilder($parameters)->getQuery()->execute()->toArray();
            }
        }else{
            $parameters = [
                'models'     => [
                    'CdrResponsible' => CdrResponsible::class,
                ],
                'conditions' => 'CdrResponsible.phoneId = :phoneId: AND typeCall = :typeCall: AND date > :cdrCountDays:',
                'bind' => [
                    'phoneId'        => self::getPhoneIndex($phone),
                    'typeCall'       => $typeCall,
                    'cdrCountDays'   => $this->cdrCountDays,
                ],
                'columns'    => [
                    'clientName' => 'CdrResponsible.number',
                    'typeCall' => 'CdrResponsible.typeCall',
                    'name'       => '""',
                    'number'     => 'CdrResponsible.innerNum',
                    'position'   => '""',
                    'source'     => '"cdr"'
                ],
            ];
            $res->data = $manager->createBuilder($parameters)->getQuery()->execute()->toArray();
        }

        return $res;
    }

    /**
     * Сохранение данных ответственных по клиентам.
     * @param $data
     * @return PBXApiResult
     */
    public function saveResponsibleData($data):PBXApiResult
    {
        $res = new PBXApiResult();
        $res->success = true;
        $this->db->begin();

        $ids = [];
        foreach ($data as $el) {
            $ids[] = $el['crmId'];
        }
        $oldRows = Responsibles::find(['crmId IN ({crmId:array})','bind' => ['crmId' => array_unique($ids)]]);
        $oldRows->delete();
        unset($ids);

        foreach ($data as $el) {
            if(empty($el['number']) || empty($el['employeeId']) ){
                $res->data[] = [
                    'number' => $el['number'],
                    'id' => ''
                ] ;
                continue;
            }
            $phoneId = self::getPhoneIndex($el['number']);
            $resp = Responsibles::findFirst("phoneId='$phoneId'");
            if(!$resp){
                $resp = new Responsibles();
            }
            foreach ($resp->toArray() as $key => $value){
                if(isset($el[$key])){
                    $resp->writeAttribute($key, $el[$key]);
                }
            }
            $resp->phoneId = $phoneId;
            $result = $resp->save();
            if(!$result){
                $res->success = false;
                $res->messages[] = [
                    'number' => $el['number'],
                    'error' => $el->getMessages()
                ];
            }else{
                $res->data[] = [
                    'number' => $el['number'],
                    'id' => $resp->id
                ] ;
            }
        }
        $this->db->commit();
        return $res;

    }

    /**
     * Удаляет данные из базы.
     * @param $data
     * @return PBXApiResult
     */
    public function deleteResponsibleData($data):PBXApiResult
    {
        $res = new PBXApiResult();
        $this->db->begin();
        foreach ($data as $el) {
            $phoneId = self::getPhoneIndex($el['number']);
            $resp = Responsibles::findFirst("phoneId='$phoneId'");
            if (!$resp) {
                $res->data[] = [
                    'number' => $el['number'],
                ];
                continue;
            }
            $result = $resp->delete();
            if($result){
                $res->data[] = [
                    'number' => $el['number'],
                ] ;
            }else{
                $res->success = false;
                $res->messages[] = [
                    'crmId' => $el['number'],
                    'error' => $resp->getMessages()
                ];
            }
        }
        $this->db->commit();
        return $res;
    }

    /**
     * Выполнение меодов worker, запущенного в другом процессе.
     * @param string $function
     * @param array $args
     * @param bool $retVal
     * @param int $timeout таймаут ожидания ответа воркера, сек.
     * @return array|bool|mixed
     */
    public static function invoke(string $function, array $args = [], bool $retVal = true, int $timeout = 20){
        $req = [
            'action'   => 'invoke',
            'function' => $function,
            'args'     => $args
        ];
        $client = new BeanstalkClient(self::class);
        try {
            if($retVal){
                $req['need-ret'] = true;
                $result = $client->request(json_encode($req, JSON_THROW_ON_ERROR), $timeout);
            }else{
                $client->publish(json_encode($req, JSON_THROW_ON_ERROR));
                return true;
            }
            $object = unserialize($result, ['allowed_classes' => [PBXApiResult::class]]);
        } catch (\Throwable $e) {
            $object = [];
        }
        return $object;
    }

    /**
     * Возвращает усеченный слева номер телефона.
     *
     * @param $number
     *
     * @return bool|string
     */
    public static function getPhoneIndex($number)
    {
        $number = preg_replace('/\D+/', '', $number);
        return substr($number, -10);
    }

    /**
     * Запускаем парсер истории звонков. Парсер сохраняет кэш, кто последний говорил с клиентом.
     * @return void
     */
    public function syncCdrData():void
    {
        $oldOffset = $this->cdrOffset;
        $cdrData = HistoryParser::getHistoryData($this->cdrOffset, $this->referenceDate);
        foreach ($cdrData as $phoneId => $cdr){
            $this->updateCdrResponsible($phoneId, $cdr);
            $this->updateCdrResponsible($phoneId, $cdr, $cdr['type']??'');
        }
        if($oldOffset !== $this->cdrOffset){
            $this->updateSettings($this->cdrOffset);
        }
    }

    /**
     * Сохранение информации по ответственному за вызов.
     * @param        $phoneId
     * @param string $typeCall
     * @return void
     */
    private function updateCdrResponsible($phoneId, $cdr, string $typeCall = "")
    {
        $filter = [
            'phoneId=:phoneId: AND typeCall=:typeCall:',
            'bind' => [
                'phoneId' => $phoneId,
                'typeCall' => $typeCall
            ]
        ];
        $cdrDb = CdrResponsible::findFirst($filter);
        if(!$cdrDb){
            $cdrDb = new CdrResponsible();
            $cdrDb->phoneId  = $phoneId;
            $cdrDb->typeCall = $typeCall;
        }
        foreach ($cdrDb->toArray() as $key => $value){
            if(isset($cdr[$key])){
                $cdrDb->writeAttribute($key, $cdr[$key]);
            }
        }
        if(!$cdrDb->save()){
            $this->logger->writeError('syncCdrData: '.$cdrDb->getMessages());
        }
    }

    /**
     * @return array
     */
    public function getSettings():array
    {
        $result = ModuleInterceptionSmartIvr::findFirst();
        if(!$result){
            $result = [];
        }else{
            $result = $result->toArray();
        }
        return $result;
    }

    /**
     * Сохранение настроек модуля.
     * Запись выполняется в процессе воркера, чтобы вся работа с SQLite шла из
     * одного процесса (исключаем конкурентную запись из веб-процесса), и сразу
     * обновляет кэш настроек воркера, не дожидаясь периодического ping (~1 мин).
     * @param array $data поля настроек, уже подготовленные контроллером.
     * @return PBXApiResult
     */
    public function saveSettings($data):PBXApiResult
    {
        $res = new PBXApiResult();
        if(!is_array($data) || empty($data)){
            $res->success = false;
            $res->messages[] = 'saveSettings: empty or invalid settings payload';
            $this->logger->writeError('saveSettings: empty or invalid settings payload');
            return $res;
        }
        try {
            $settings = ModuleInterceptionSmartIvr::findFirst();
            if(!$settings){
                $settings = new ModuleInterceptionSmartIvr();
            }
            foreach ($settings->toArray() as $key => $value){
                if($key !== 'id' && array_key_exists($key, $data)){
                    $settings->writeAttribute($key, $data[$key]);
                }
            }
            $res->success = $settings->save();
            if(!$res->success){
                // Объекты Phalcon\Messages\Message не переживут unserialize() с
                // ограничением allowed_classes в invoke() — приводим к строкам.
                $errors = array_map('strval', $settings->getMessages());
                $res->messages = $errors;
                $this->logger->writeError('saveSettings: '.implode('; ', $errors));
            }else{
                $res->data = $settings->toArray();
                // Воркер сразу подхватывает новые настройки в свой кэш.
                $this->updateSettings();
            }
        } catch (\Throwable $e) {
            $res->success = false;
            $res->messages[] = $e->getMessage();
            $this->logger->writeError('saveSettings: '.$e->getMessage());
        }
        return $res;
    }
}

if(isset($argv) && count($argv) !== 1){
    ConnectorDB::startWorker($argv??[]);
}
