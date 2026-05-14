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

/**
 * Class CdrResponsible
 *
 * @package Modules\ModuleInterceptionSmartIvr\Models
 * @Indexes(
 *     [name='innerNum', columns=['innerNum'], type=''],
 *     [name='date', columns=['date'], type=''],
 *     [name='typeCall', columns=['typeCall'], type=''],
 *     [name='phoneId', columns=['phoneId'], type='']
 * )
 */
class CdrResponsible extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Идентификатор номера телефона
     *
     * @Column(type="string", nullable=true)
     */
    public $phoneId;

    /**
     * Номер клиента
     *
     * @Column(type="string", nullable=true)
     */
    public $number;

    /**
     * Дата последнего звонка.
     *
     * @Column(type="string", nullable=true)
     */
    public $date;

    /**
     * Идентификатор звонка.
     *
     * @Column(type="string", nullable=true)
     */
    public $linkedid;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $typeCall;

    /**
     * Внутренний номер сотрудника. Последний, кто говорил с клиентом.
     * @Column(type="string", nullable=true)
     */
    public $innerNum;

    /**
     * @param $calledModelObject
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