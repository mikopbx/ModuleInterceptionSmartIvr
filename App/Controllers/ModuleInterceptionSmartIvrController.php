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

namespace Modules\ModuleInterceptionSmartIvr\App\Controllers;
use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\AdminCabinet\Providers\AssetProvider;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\Providers;
use MikoPBX\Modules\PbxExtensionUtils;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleInterceptionSmartIvr\App\Forms\ModuleInterceptionSmartIvrForm;
use Modules\ModuleInterceptionSmartIvr\bin\ConnectorDB;
use Modules\ModuleInterceptionSmartIvr\Models\ModuleInterceptionSmartIvr;

class ModuleInterceptionSmartIvrController extends BaseController
{
    private string $moduleUniqueID = 'ModuleInterceptionSmartIvr';
    private string $moduleDir;

    /**
     * Basic initial class
     */
    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
        $this->view->logoImagePath = $this->url->get().'assets/img/cache/'.$this->moduleUniqueID.'/logo.svg';
        $this->view->submitMode = null;
        parent::initialize();
    }

    /**
     * Renders the index page for the module.
     *
     * @return void
     */
    public function indexAction(): void
    {
        // Add JavaScript files to the footer collection
        $footerCollectionJS = $this->assets->collection(AssetProvider::FOOTER_JS);
        $footerCollectionJS
            ->addJs('js/pbx/main/form.js', true)
            ->addJs('js/cache/'.$this->moduleUniqueID.'/module-interception-smart-ivr-modify.js', true);

        // Retrieve or create new module settings
        $settings = ModuleInterceptionSmartIvr::findFirst();
        if ($settings === null) {
            $settings = new ModuleInterceptionSmartIvr();
            $settings->timeoutInterception = 5;
            $settings->textInvalidNumber = 'Не правильно набран номер';
            $settings->textNumberBusy = 'Сотрудник сейчас разговаривает';
            $settings->textIvr = 'Уже идет дозвон до ответвленного: <user> <position>. Для связи с другим сотрудником наберите добавочный или нажмите цифру 1, чтобы прослушать голосовое меню.';
        }
        $options = [];

        // Retrieve providers list for form
        $providers = Providers::find();
        $providersList = [];
        foreach ($providers as $provider){
            $providersList[ $provider->uniqid ] = $provider->getRepresent();
        }
        // Список всех используемых эктеншенов
        $forwardingExtensions[''] = $this->translation->_('ex_SelectNumber');
        $parameters               = [
            'conditions' => 'type = ({type})',
            'bind'       => [
                'type' => Extensions::TYPE_IVR_MENU,
            ],
        ];
        $extensions               = Extensions::find($parameters);
        foreach ($extensions as $record) {
            $forwardingExtensions[$record->number] = $record ? ($record->getRepresent() . " <$record->number>") : '';
        }
        $options['extensions'] = $forwardingExtensions;
        $options['providers']  = $providersList;

        $this->view->form = new ModuleInterceptionSmartIvrForm($settings, $options);
        $this->view->pick('Modules/'.$this->moduleUniqueID.'/ModuleInterceptionSmartIvr/modify');
    }

    /**
     * Saves the form data to the database.
     *
     * @return void
     */
    public function saveAction() :void
    {
        if (!$this->request->isPost()) {
            return;
        }
        $data = $this->request->getPost();
        $record = ModuleInterceptionSmartIvr::findFirstById($data['id']);
        if ($record === null) {
            $record = new ModuleInterceptionSmartIvr();
        }
        foreach ($record as $key => $value) {
            switch ($key) {
                case 'id':
                    break;
                case 'userGroups':
                    $record->$key = json_encode($data[$key]);
                    break;
                case 'typeCallCdr':
                    $record->$key = trim($data[$key]);
                    break;
                case 'simpleMode':
                case 'disableIvr':
                    if (array_key_exists($key, $data)) {
                        $record->$key = ($data[$key] === 'on') || intval($data[$key]) === 1 ? '1' : '0';
                    } else {
                        $record->$key = '0';
                    }
                    break;
                default:
                    if (array_key_exists($key, $data)) {
                        $record->$key = $data[$key];
                    } else {
                        $record->$key = '';
                    }
            }
        }

        // Если модуль включён — воркер ConnectorDB запущен; сохраняем через него,
        // чтобы вся запись в SQLite шла из одного процесса и воркер сразу обновил
        // свой кэш настроек. Если модуль выключен (воркера нет) или воркер не
        // ответил — резервный путь: прямое сохранение через модель.
        if (PbxExtensionUtils::isEnabled($this->moduleUniqueID)
            && $this->saveViaConnector($record)) {
            return;
        }
        // Сюда попадаем и при ошибке валидации в воркере (success=false): saveEntity()
        // повторит save() в веб-процессе и покажет сообщения об ошибке в форме —
        // воркер-путь их не отдаёт. Дублирования записи нет: при success=false воркер
        // ничего не сохранил.
        $this->saveEntity($record);
    }

    /**
     * Пытается сохранить настройки через воркер ConnectorDB.
     *
     * @param ModuleInterceptionSmartIvr $record подготовленная запись настроек.
     * @return bool true — сохранено воркером; false — воркер не ответил, нужен резервный путь.
     */
    private function saveViaConnector(ModuleInterceptionSmartIvr $record): bool
    {
        try {
            // Короткий таймаут: если воркер не отвечает, быстро уходим на резервный путь.
            $result = ConnectorDB::invoke(ConnectorDB::SAVE_SETTINGS, [$record->toArray()], true, 3);
        } catch (\Throwable $e) {
            // Сбой автозагрузки/вызова ConnectorDB в веб-процессе — уходим в резервный путь.
            return false;
        }
        if (!$result instanceof PBXApiResult || !$result->success) {
            return false;
        }
        if ($this->request->isAjax()) {
            $this->view->success = true;
        } else {
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        }
        return true;
    }

    /**
     * Deletes a record from db.
     *
     * @param string $recordId
     * @return void
     */
    public function deleteAction(string $recordId): void
    {
        $record = ModuleInterceptionSmartIvr::findFirstById($recordId);
        if ($record !== null) {
            $this->deleteEntity($record,'module-interception-smart-ivr/module-interception-smart-ivr/index');
        }
    }

}