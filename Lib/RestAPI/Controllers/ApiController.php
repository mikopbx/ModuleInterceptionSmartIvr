<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 4 2020
 *
 */

namespace Modules\ModuleInterceptionSmartIvr\Lib\RestAPI\Controllers;

use MikoPBX\PBXCoreREST\Controllers\Modules\ModulesControllerBase;
use Modules\ModuleInterceptionSmartIvr\bin\ConnectorDB;

class ApiController extends ModulesControllerBase
{
    /**
     * Синхронизация пользоватлей CRM системы.
     * curl -X POST -d '[{"crmId":"crm-0","name":"Employee 0","position":"Position 0","number":100},{"crmId":"crm-1","name":"Employee 1","position":"Position 1","number":101}]' http://127.0.0.1/pbxcore/api/module-interception-ivr/v1/users/add
     */
    public function postSaveUsers():void
    {
        $data =  $this->getDataFromBody('postSaveUsers');
        $result = ConnectorDB::invoke(ConnectorDB::SAVE_USERS, [$data]);
        if($result){
            $this->echoResponse($result->getResult());
        }
        $this->response->sendRaw();
    }

    private function getDataFromBody($logName):array
    {
        $data = [];
        $body = $this->request->getRawBody();
        // file_put_contents("/tmp/test_$logName.log", print_r($body, true));

        $pos1 = strpos($body, '[');
        $pos2 = strpos($body, '{');
        $pos = false;
        if($pos1 !== false && $pos2 !== false){
            $pos = min($pos1, $pos2);
        }elseif ($pos1 !== false){
            $pos = $pos1;
        }elseif ($pos2 !== false){
            $pos = $pos2;
        }
        if ($pos !== false) {
            $substr = substr($body, $pos, strlen($body));
            try {
                $data = json_decode(trim($substr), true, 512, JSON_THROW_ON_ERROR);
            }catch (\Exception $e){
                $data = [];
            }
        }
        return $data;
    }

    /**
     * Удаление пользователей CRM системы.
     * curl -X POST -d '[{"crmId":"crm-0"},{"crmId":"crm-1"}]' http://127.0.0.1/pbxcore/api/module-interception-ivr/v1/users/delete
     * @return void
     */
    public function postDeleteUsers():void
    {
        $data =  $this->getDataFromBody('postDeleteUsers');
        $result = ConnectorDB::invoke(ConnectorDB::DELETE_USERS, [$data]);
        if($result){
            $this->echoResponse($result->getResult());
        }
        $this->response->sendRaw();
    }

    /**
     * Синхронизация клиентов CRM системы.
     * curl -X POST -d '[{"crmId":"9802eadb-6d9f-11ee-bb62-005056882bf6", "employeeId":"0000eadb-6d9f-11ee-bb62-005056882bf6","name":"Client 0","number":"74952293043"}]' http://127.0.0.1/pbxcore/api/module-interception-ivr/v1/responsible/add
     * curl -X POST -d '[{"crmId":"9802eadb-6d9f-11ee-bb62-005056882bf6", "employeeId":"0000eadb-6d9f-11ee-bb62-005056882bf6","name":"Client 0","number":"74952293043"},{"crmId":"0000eadb-6d9f-11ee-bb62-005056882bf6", "employeeId":"9802eadb-6d9f-11ee-bb62-005056882bf6","name":"Client 1","number":"74952293049"}]' http://127.0.0.1/pbxcore/api/module-interception-ivr/v1/responsible/add
     * @return void
     */
    public function postSaveResponsible():void
    {
        $data =  $this->getDataFromBody('postSaveResponsible');
        $result = ConnectorDB::invoke(ConnectorDB::SAVE_RESPONSIBLE, [$data]);
        if($result){
            $this->echoResponse($result->getResult());
        }
        $this->response->sendRaw();
    }

    /**
     * Удаление клиентов CRM системы.
     * curl -X POST -d '[{"number":"74952293042"},{"number":"74952293044"}]' http://127.0.0.1/pbxcore/api/module-interception-ivr/v1/responsible/delete
     * @return void
     */
    public function postDeleteResponsible():void
    {
        $data =  $this->getDataFromBody('postDeleteResponsible');
        $result = ConnectorDB::invoke(ConnectorDB::DELETE_RESPONSIBLE, [$data]);
        if($result){
            $this->echoResponse($result->getResult());
        }
        $this->response->sendRaw();
    }

    /**
     * Получение ответственного за номер телефона
     * curl -X GET 'http://127.0.0.1/pbxcore/api/module-interception-ivr/v1/responsible/74353555555&type=OUTGOING'
     * curl -X GET 'http://127.0.0.1/pbxcore/api/module-interception-ivr/v1/responsible/74353555555&type=INCOMING'
     * curl -X GET 'http://127.0.0.1/pbxcore/api/module-interception-ivr/v1/responsible/74353555555&type='
     * @param string $phone
     * @return void
     */
    public function getResponsible(string $phone):void
    {
        $typeCall = $this->request->get('type')??'';
        $result = ConnectorDB::invoke(ConnectorDB::GET_RESPONSIBLE, [$phone, $typeCall]);
        if($result){
            $this->echoResponse($result->getResult());
        }
        $this->response->sendRaw();
    }

    /**
     * Вывод ответа сервера.
     * @param $result
     * @return void
     */
    private function echoResponse($result):void
    {
        $filename = $result['data']['results']??'';
        if(file_exists($filename)){
            try {
                $result['data']['results'] = json_decode(file_get_contents($filename), true, 512, JSON_THROW_ON_ERROR);
            }catch ( \JsonException $e){
                $result['data']['results'] = [];
            }
            unlink($filename);
        }
        try {
            echo json_encode($result, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
        }catch (\Exception $e){
            echo 'Error json encode: '. print_r($result, true);
        }
    }
}