#!/usr/bin/php
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

use MikoPBX\Core\Asterisk\AGI;
use MikoPBX\Core\System\Util;
use Modules\ModuleInterceptionSmartIvr\Lib\YandexSynthesize;
use Modules\ModuleInterceptionSmartIvr\bin\ConnectorDB;
use Modules\ModuleUsersGroups\Models\GroupMembers;
use MikoPBX\Common\Models\Extensions;
use Modules\ModuleInterceptionSmartIvr\Lib\MikoPBXVersion;

require_once 'Globals.php';

function getExtensionStatus($agi, $number): int
{
    $state   = $agi->get_variable("DEVICE_STATE(PJSIP/$number)", true);
    $dExists = $agi->get_variable("DIALPLAN_EXISTS(internal,$number,1)", true);
    $agi->verbose("DEVICE_STATE: {$state} DIALPLAN_EXISTS: $dExists");
    $stateTable = [
        'UNKNOWN'       => ['Status'=> -1, 'StatusText' => 'Unknown'],
        'INVALID'       => ['Status'=> -1, 'StatusText' => 'Unknown'],
        'NOT_INUSE'     => ['Status'=> 0, 'StatusText' => 'Idle'],
        'INUSE'         => ['Status'=> 1, 'StatusText' => 'In Use'],
        'BUSY'          => ['Status'=> 2, 'StatusText' => 'Busy'],
        'UNAVAILABLE'   => ['Status'=> 4, 'StatusText' => 'Unavailable'],
        'RINGING'       => ['Status'=> 8, 'StatusText' => 'Ringing'],
        'ONHOLD'        => ['Status'=> 16, 'StatusText' => 'On Hold'],
    ];
    if($state === 'INVALID' && $dExists === '1'){
        $result = $stateTable['NOT_INUSE'];
    }else{
        $result = $stateTable[$state]??$stateTable['UNKNOWN'];
    }
    $status = $result['Status'];
    try {
        $json_status = json_encode($result, JSON_THROW_ON_ERROR);
        $agi->verbose("Extension {$number} state is -> $status. JSON:$json_status");
    }catch (\JsonException $e) {
        $agi->verbose("Extension {$number} state is -> $status");
    }
    return $status;
}

function getDialStatus($agi)
{
    // M_DIALSTATUS в диалплане MikoPBX выставляется на MASTER_CHANNEL только когда
    // на вызов реально ответил сотрудник. Читаем его функцией диалплана IMPORT (без AMI).
    // Имя мастер-канала резолвим отдельным вызовом, чтобы не зависеть от раскрытия
    // вложенного ${...} в AGI get_variable. DIALSTATUS — лишь запасной вариант
    // (при переадресации в кастомный диалплан он может дать ложный ANSWER).
    $validStatuses = ['ANSWER', 'NOANSWER', 'BUSY', 'CANCEL', 'CONGESTION', 'CHANUNAVAIL'];

    $chan = $agi->get_variable('MASTER_CHANNEL(CHANNEL)', true);
    if (!empty($chan)) {
        $masterStatus = strtoupper((string)$agi->get_variable("IMPORT($chan,M_DIALSTATUS)", true));
        if (in_array($masterStatus, $validStatuses, true)) {
            return $masterStatus;
        }
    }

    return $agi->get_variable('DIALSTATUS', true);
}

function numberAllow($agi, $ids, $number):bool
{
    $ids = json_decode($ids, true);
    if(!is_array($ids) || empty($ids) || !
        class_exists('\Modules\ModuleUsersGroups\Models\UsersGroups')) {
        $agi->verbose('numberAllow $ids:'.json_encode($ids));
        return true;
    }

    $filter = [
        'group_id IN ({group_id:array})',
        'bind' => [
            'group_id' => array_unique($ids)
        ],
        'columns' => 'user_id'
    ];
    $uIds = array_column(GroupMembers::find($filter)->toArray(), 'user_id');
    $groupNumber = [];
    if (!empty($uIds)) {
        $filter = [
            'type=:type: AND userid IN ({userid:array})',
            'bind' => [
                'type' => Extensions::TYPE_SIP,
                'userid' => $uIds,
            ],
            'columns' => 'number,userid'

        ];
        $groupNumber = array_column(Extensions::find($filter)->toArray(), 'number');
    }
    $agi->verbose('numberAllow $uIds:'.json_encode($uIds).', $groupNumber'.json_encode($groupNumber));
    return in_array($number, $groupNumber , true);
}

$agi = new AGI();
$agi->exec('Ringing', '');
$agi->set_variable('AGIEXITONHANGUP', 'yes');
$agi->set_variable('AGISIGHUP', 'yes');
$agi->set_variable('__ENDCALLONANSWER', 'yes');

// Короткий таймаут вместо дефолтных 20 c: если воркер ConnectorDB недоступен
// (мёртв/перезапускается), живой звонок не должен висеть до 20 c перед тем, как
// уйти в штатную маршрутизацию. При здоровом воркере ответ приходит за мс.
$workerTimeout = 5;
$settings = ConnectorDB::invoke(ConnectorDB::GET_SETTINGS, [], true, $workerTimeout);
if(empty($settings)){
    exit();
}else{
    $settings = (object)$settings;
}
$number = $agi->request['agi_callerid'];
if($number === 'asterisk'){
    $agi->verbose('Ignore call from CID asterisk');
    exit();
}
$result = ConnectorDB::invoke(ConnectorDB::GET_RESPONSIBLE, [$number, $settings->typeCallCdr], true, $workerTimeout);

$gotoFailDst = true;
if(!$result){
    $agi->verbose('No response from ConnectorDB');
}elseif (empty($result->data)){
    $agi->verbose('Responsible not found - new contact');
}elseif(empty($result->data[0]['number'])){
    $agi->verbose('Responsible not found - old contact');
}else{
    $gotoFailDst = false;
}
$agi->verbose("simpleMode: '$settings->simpleMode' typeCallCdr: '$settings->typeCallCdr'");
if(intval($settings->simpleMode) === 1){
    $responsibleNumber = $result->data[0]['number']??'';
    if($gotoFailDst === false){
        $status = getExtensionStatus($agi, $responsibleNumber);
        $allowed = numberAllow($agi, $settings->userGroups,$responsibleNumber);
        if(!$allowed){
            $agi->Verbose("$responsibleNumber - deny intrception...");
        }elseif(strlen($number) < 5){
            $agi->verbose("Number $number is internal... deny intrception");
        }elseif($status === 0){
            $agi->set_variable('__pt1c_UNIQUEID', '');
            $agi->exec( 'Dial', "Local/$responsibleNumber@internal/n,$settings->interceptionDialDuration," . 'TtekKHhU(dial_answer)b(dial_create_chan,s,1)');
            $DialStatus = getDialStatus($agi);
            $agi->Verbose("Dial status after connect ($DialStatus).");
            if ('ANSWER' === strtoupper($DialStatus)) {
                $agi->Verbose('Call answered the script sends HANGUP command to PBX');
                $agi->hangup();
                return;
            }
        }else{
            $agi->verbose("Status is $status - not 0");
        }
    }
    exit();
}

$agi->verbose("disableIvr: '{$settings->disableIvr}'");
if(empty($settings->yandexApiKey) || $settings->disableIvr === '1'){
    if($gotoFailDst){
        exit(0);
    }
}else{
    if($gotoFailDst){
        $agi->verbose('Goto fail dst...');
        if(getExtensionStatus($agi, $settings->failover_extension)!==1){
            $agi->exec_goto('internal', (string)$settings->failover_extension, '1');
        }
        exit(0);
    }
    $tts     = new YandexSynthesize(dirname(__DIR__)."/db/tts", $settings->yandexApiKey);
    $textIvr = str_replace(['<user>', '<position>'],[$result->data[0]['name'], $result->data[0]['position']],$settings->textIvr);
    $fullFilename = $tts->makeSpeechFromText(strip_tags($textIvr), 'ru-RU');
    if(!file_exists($fullFilename)){
        $agi->verbose('Failed to create an audio file');
        exit(3);
    }
    $agi->set_variable('M_FILENAME_IVR', Util::trimExtensionForFile($fullFilename));
    $fullFilename = $tts->makeSpeechFromText(strip_tags($settings->textInvalidNumber), 'ru-RU');
    if(!file_exists($fullFilename)){
        $agi->verbose('UNKNOWN: Failed to create an audio file');
        exit(3);
    }
    $agi->set_variable('M_FILENAME_INVALID_NUM', Util::trimExtensionForFile($fullFilename));
    $agi->set_variable('M_USER_NUMBER', $result->data[0]['number']);
}

$peerMobile = '';
$conf = "Channel: Local/{$result->data[0]['number']}@interception-orig-leg-1".PHP_EOL.
    "Callerid: $number <$number>".PHP_EOL.
    "Application: Wait".PHP_EOL.
    "Data: 300".PHP_EOL.
    "Archive: no".PHP_EOL.
    "Setvar: _DST_CONTEXT=interception-bridge".PHP_EOL.
    "Setvar: _origCidName=$number".PHP_EOL.
    "Setvar: _INTECEPTION_CNANNEL={$agi->request['agi_channel']}".PHP_EOL.
    "Setvar: _OLD_LINKEDID=".$agi->get_variable('CHANNEL(linkedid)',true).PHP_EOL.
    "Setvar: _peer_mobile=$peerMobile";

$di = MikoPBXVersion::getDefaultDi();
$outgoingDir = $di->getShared('config')->path('asterisk.astspooldir').'/outgoing';
$tmpDir      = $di->getShared('config')->path('core.tempDir');

$tmpFileName = tempnam($tmpDir, 'call');
$newFilename = "$outgoingDir/$number.call";

file_put_contents($tmpFileName, $conf);
$mvPath = Util::which('mv');
$touchPath = Util::which('touch');
shell_exec("$touchPath -m -d '".date("Y-m-d H:i:s", strtotime("+$settings->timeoutInterception seconds"))."' $tmpFileName");
shell_exec("$mvPath $tmpFileName $newFilename");