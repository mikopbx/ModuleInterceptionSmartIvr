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

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\System\PBX;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleInterceptionSmartIvr\bin\ConnectorDB;
use Modules\ModuleInterceptionSmartIvr\Lib\RestAPI\Controllers\ApiController;

class InterceptionSmartIvrConf extends ConfigClass
{

    /**
     * Receive information about mikopbx main database changes
     *
     * @param $data
     */
    public function modelsEventChangeData($data): void
    {
    }

    /**
     * Returns module workers to start it at WorkerSafeScriptCore
     *
     * @return array
     */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker' => ConnectorDB::class,
            ],
        ];
    }

    /**
     *  Process CoreAPI requests under root rights
     *
     * @param array $request
     *
     * @return PBXApiResult An object containing the result of the API call.
     */
    public function moduleRestAPICallback(array $request): PBXApiResult
    {
        $res    = new PBXApiResult();
        $res->processor = __METHOD__;
        $action = strtoupper($request['action']);
        switch ($action) {
            case 'CHECK':
            case 'RELOAD':
                $res->success = true;
                break;
            default:
                $res->success    = false;
                $res->messages[] = 'API action not found in moduleRestAPICallback ModuleInerceptionSmartIvr';
        }
        return $res;
    }


    /**
     * Кастомизация входящего контекста для конкретного маршрута.
     *
     * @param $rout_number
     *
     * @return string
     */
    public function generateIncomingRoutBeforeDial($rout_number): string
    {
        // Перехват на ответственного.
        return "\tsame => n,Gosub(interception-smart-ivr,s,1)";
    }

    /**
     * Generates additional contexts for the queue.
     *
     * @return string The generated extension contexts.
     */
    public function extensionGenContexts(): string
    {

        // Generate internal numbering plan.
        $conf = PHP_EOL."[interception-smart-ivr]".PHP_EOL;
        $conf .= "exten => s,1,AGI($this->moduleDir/agi-bin/smartIVR.php)" . PHP_EOL;
        $conf .= "\t". 'same => n,ExecIf($["${M_USER_NUMBER}x" == "x"]?return)'.PHP_EOL;
        $conf .= "\t". 'same => n,Background(${M_FILENAME_IVR})'.PHP_EOL;
        $conf .= "\t". 'same => n,ExecIf($["${M_DIALSTATUS}" == "ANSWER"]?hangup)'.PHP_EOL;
        $conf .= "\t". "same => n,WaitExten(60,m)".PHP_EOL;
        $conf .= "\t". "same => n,return".PHP_EOL.PHP_EOL;

        $conf .= 'exten => _[t],1,UserEvent(InterceptionSmartIvr,chan: ${CHANNEL}, id: ${CHANNEL(linkedid)}, action: exit, ext: ${EXTEN})' . PHP_EOL;
        $conf .= "\t". 'same => n,return'.PHP_EOL.PHP_EOL;

        $plan  = "\t". 'same => n,ExecIf($["${EXTENSION_EXISTS}" != "1"]?Playback(${M_FILENAME_INVALID_NUM}))'.PHP_EOL;
        $plan .= "\t". 'same => n,ExecIf($["${EXTENSION_EXISTS}" != "1"]?return)'.PHP_EOL;
        $plan .= "\t". 'same => n,Set(M_NUMBER_ENTERED=${EXTEN})'.PHP_EOL;
        $plan .= "\t". 'same => n,UserEvent(InterceptionSmartIvr,chan: ${CHANNEL}, id: ${CHANNEL(linkedid)}, action: stop)'.PHP_EOL;
        $plan .= "\t". 'same => n,Goto(internal,${EXTEN},1)'.PHP_EOL;
        $plan .= "\t". "same => n,return".PHP_EOL.PHP_EOL;

        $appNumbers = Extensions::find(["type='QUEUE' OR type='IVR MENU'", 'columns' => 'number']);
        foreach ($appNumbers as $app) {
            $conf .= 'exten => '.$app->number.',1,Set(EXTENSION_EXISTS=${DIALPLAN_EXISTS(internal,${EXTEN},1)})' . PHP_EOL;
            $conf .= $plan;
        }
        $conf .= 'exten => _XX,1,Set(EXTENSION_EXISTS=${DIALPLAN_EXISTS(internal-users,${EXTEN},1)})' . PHP_EOL;
        $conf .= $plan;
        $conf .= 'exten => _XXX,1,Set(EXTENSION_EXISTS=${DIALPLAN_EXISTS(internal-users,${EXTEN},1)})' . PHP_EOL;
        $conf .= $plan;
        $conf .= 'exten => _XXXX,1,Set(EXTENSION_EXISTS=${DIALPLAN_EXISTS(internal-users,${EXTEN},1)})' . PHP_EOL;
        $conf .= $plan;

        $conf .= '[interception-orig-check-state]'.PHP_EOL.
            'exten => s,1,Set(INTECEPTION_CNANNEL=${IMPORT(${HOOK_CHANNEL},INTECEPTION_CNANNEL)})'.PHP_EOL."\t".
            'same => n,ExecIf($[ "${CHANNEL_EXISTS(${INTECEPTION_CNANNEL})}" == "0" ]?ChannelRedirect(${HOOK_CHANNEL},interception-orig-leg-1,h,1))'.PHP_EOL."\t".
            'same => n,ExecIf($[ "${IMPORT(${INTECEPTION_CNANNEL},M_DIALSTATUS)}" == "ANSWER" ]?ChannelRedirect(${HOOK_CHANNEL},interception-orig-leg-1,h,1))'.PHP_EOL.
            'same => n,ExecIf($[ "${IMPORT(${INTECEPTION_CNANNEL},M_NUMBER_ENTERED)}x" != "x" ]?ChannelRedirect(${HOOK_CHANNEL},interception-orig-leg-1,h,1))'.PHP_EOL.
            PHP_EOL.
            '[interception-orig-leg-1]'.PHP_EOL.
            'exten => failed,1,Hangup()'.PHP_EOL."\t".
            'exten => _[0-9*#+a-zA-Z][0-9*#+a-zA-Z]!,1,Answer()'.PHP_EOL."\t".
            'same => n,Set(_CALLER=${EXTEN})'.PHP_EOL."\t".
            'same => n,ExecIf($["${origCidName}x" != "x"]?Set(CALLERID(name)=${origCidName}))'.PHP_EOL."\t".
            'same => n,Set(CONTACTS=${PJSIP_DIAL_CONTACTS(${EXTEN})})'.PHP_EOL."\t".
            'same => n,Set(_PT1C_SIP_HEADER=${SIPADDHEADER})'.PHP_EOL."\t".
            'same => n,ExecIf($["${FIELDQTY(CONTACTS,&)}" != "1" && "${ALLOW_MULTY_ANSWER}" != "1"]?Set(__PT1C_SIP_HEADER=${EMPTY_VAR}))'.PHP_EOL."\t".
            'same => n,GosubIf($["${INTECEPTION_CNANNEL}x" != "x"]?interception-set-periodic-hook,s,1)'.PHP_EOL."\t".
            'same => n,Dial(${CONTACTS},30,b(originate-create-channel,${EXTEN},1)G(interception-orig-leg-2^${CALLERID(num)}^1))'.PHP_EOL.
            'exten => h,1,NoOp(=== SPAWN EXTENSION ===)'.PHP_EOL.
            PHP_EOL.
            '[interception-set-periodic-hook]'.PHP_EOL.
            'exten => s,1,Set(BEEPID=${PERIODIC_HOOK(interception-orig-check-state,s,1)})'.PHP_EOL."\t".
            'same => n,return'.PHP_EOL.
            PHP_EOL.
            '[interception-orig-leg-2]'.PHP_EOL.
            'exten => _[0-9*#+a-zA-Z]!,1,goto(caller)'.PHP_EOL.
            'exten => _[0-9*#+a-zA-Z]!,1001(caller),Answer()'.PHP_EOL."\t".
            'same => n,Hangup()'.PHP_EOL.
            'exten => _[0-9*#+a-zA-Z]!,2,goto(callee)'.PHP_EOL.
            'exten => _[0-9*#+a-zA-Z]!,2002(callee),Goto(${DST_CONTEXT},${EXTEN},1)'.PHP_EOL.
            'exten => h,1,NoOp(=== SPAWN EXTENSION ===)'.PHP_EOL;
        return $conf;
    }

    /**
     * REST API модуля.
     * @return array[]
     */
    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [ApiController::class, 'postSaveUsers','/pbxcore/api/module-interception-ivr/v1/users/add', 'post', '/', false],
            [ApiController::class, 'postDeleteUsers',   '/pbxcore/api/module-interception-ivr/v1/users/delete', 'post', '/', false],
            [ApiController::class, 'postSaveResponsible','/pbxcore/api/module-interception-ivr/v1/responsible/add', 'post', '/', false],
            [ApiController::class, 'getResponsible','/pbxcore/api/module-interception-ivr/v1/responsible/{phone}', 'get', '/', false],
            [ApiController::class, 'postDeleteResponsible',   '/pbxcore/api/module-interception-ivr/v1/responsible/delete', 'post', '/', false],
        ];
    }

    /**
     * Process after disable action in web interface
     *
     * @return void
     */
    public function onAfterModuleDisable(): void
    {
        PBX::dialplanReload();
    }

    /**
     * Process after enable action in web interface
     *
     * @return void
     * @throws \Exception
     */
    public function onAfterModuleEnable(): void
    {
        PBX::dialplanReload();
    }
}