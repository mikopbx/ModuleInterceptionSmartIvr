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

namespace Modules\ModuleInterceptionSmartIvr\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class ModuleInterceptionSmartIvr extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Integer field example
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $cdrOffset = 1;

    /**
     * Глубина анализа истории звонков в ЧАСАХ.
     * Имя поля историческое (Days), но значение трактуется и применяется как часы —
     * см. ConnectorDB::updateSettings() и UI-метку mo_ModuleModuleInterceptionSmartIvr_cdrCountH.
     *
     * @Column(type="integer", default="60", nullable=true)
     */
    public $cdrCountDays = 60;

    /**
     * Integer field example
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $disableIvr = 1;

    /**
     * Integer field example
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $simpleMode = 1;

    /**
     * @Column(type="string", default="", nullable=true)
     */
    public $typeCallCdr = '';

    /**
     * Integer field example
     *
     * @Column(type="string", default="", nullable=true)
     */
    public $userGroups = "";

    /**
     * На сколько секунд отложить перехват на ответственного.
     *
     * @Column(type="integer", default="5", nullable=true)
     */
    public $timeoutInterception = 5;

    /**
     * Как долго пытаться звонить ответственному.
     *
     * @Column(type="integer", default="30", nullable=true)
     */
    public $interceptionDialDuration = 30;

    /**
     * Дата, с которой начинать загрузку истории звонков.
     *
     * @Column(type="string", nullable=true)
     */
    public $referenceDate = '';

    /**
     * Основной текст Smart Ivr
     *
     * @Column(type="string", nullable=true)
     */
    public $textIvr;

    /**
     * Текст "Не корректный добавочный"
     *
     * @Column(type="string", nullable=true)
     */
    public $textInvalidNumber;

    /**
     * Текст "Абонент сейчас разговаривает"
     *
     * @Column(type="string", nullable=true)
     */
    public $textNumberBusy;

    /**
     * Резервный Extension
     *
     * @Column(type="string", nullable=true)
     */
    public $failover_extension;

    /**
     * API Key
     *
     * @Column(type="string", nullable=true)
     */
    public $yandexApiKey;



    /**
     * Text field example
     *
     * @Column(type="string", nullable=true)
     */
    public $text_field;

    /**
     * TextArea field example
     *
     * @Column(type="string", nullable=true)
     */
    public $text_area_field;

    /**
     * Password field example
     *
     * @Column(type="string", nullable=true)
     */
    public $password_field;

    /**
     * Integer field example
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $integer_field;

    /**
     * CheckBox
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $checkbox_field;

    /**
     * Toggle
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $toggle_field;

    /**
     * Dropdown menu
     *
     * @Column(type="string", nullable=true)
     */
    public $dropdown_field;

    /**
     * Returns dynamic relations between module models and common models
     * MikoPBX check it in ModelsBase after every call to keep data consistent
     *
     * There is example to describe the relation between Providers and ModuleInerceptionSmartIvr models
     *
     * It is important to duplicate the relation alias on message field after Models\ word
     *
     * @param $calledModelObject
     *
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    public function initialize(): void
    {
        parent::initialize();
    }


}