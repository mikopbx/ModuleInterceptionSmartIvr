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
 * Class Responsibles
 *
 * @package Modules\ModuleInterceptionSmartIvr\Models
 * @Indexes(
 *     [name='employeeId', columns=['employeeId'], type=''],
 *     [name='phoneId', columns=['phoneId'], type='']
 * )
 */
class Responsibles extends ModulesModelsBase
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
     * ВНомер телефона
     *
     * @Column(type="string", nullable=true)
     */
    public $number;

    /**
     * Наименование клиента.
     *
     * @Column(type="string", nullable=true)
     */
    public $name;

    /**
     * Идентификатор сотрудника в 1С.
     *
     * @Column(type="string", nullable=true)
     */
    public $employeeId;

    /**
     * Идентификатор клиента в 1С.
     *
     * @Column(type="string", nullable=true)
     */
    public $crmId;

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