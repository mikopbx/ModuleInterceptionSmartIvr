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

namespace Modules\ModuleInterceptionSmartIvr\App\Forms;

use MikoPBX\AdminCabinet\Forms\BaseForm;
use MikoPBX\Core\System\Util;
use Modules\ModuleInterceptionSmartIvr\Lib\HistoryParser;
use Modules\ModuleUsersGroups\Models\UsersGroups;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Password;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\Hidden;
use Phalcon\Forms\Element\Select;

class ModuleInterceptionSmartIvrForm extends BaseForm
{

    public function initialize($entity = null, $options = null) :void
    {
        $this->add(new Hidden('id', ['value' => $entity->id]));
        $this->addTextArea('textIvr',$entity->textIvr??'',90,
                           ['placeholder' => 'Введите текст основного IVR сообщения.']
        );
        $this->addTextArea('textInvalidNumber',$entity->textInvalidNumber??'',90,
                           ['placeholder' => 'Введите текст сообщения для оповещения, что не верно набран номер.']
        );
        $this->addTextArea('textNumberBusy',$entity->textNumberBusy??'',90,
                           ['placeholder' => 'Введите текст сообщения для оповещения, что абонент сейчас разговаривает.']
        );
        $extension = new Select(
            'failover_extension', $options['extensions'], [
                                    'using'    => [
                                        'id',
                                        'name',
                                    ],
                                    'useEmpty' => false,
                                    'class'    => 'ui selection dropdown search forwarding-select',
                                ]
        );
        $this->add($extension);
        $this->add(new Text('yandexApiKey'));


        $this->add(new Numeric('timeoutInterception', [
            'maxlength'    => 2,
            'style'        => 'width: 80px;',
            'defaultValue' => 5,
        ]));

        $this->add(new Numeric('interceptionDialDuration', [
            'maxlength'    => 3,
            'style'        => 'width: 80px;',
            'defaultValue' => 30,
        ]));

        // cdrCountDays — глубина анализа истории звонков в ЧАСАХ (имя поля историческое).
        $this->add(new Numeric('cdrCountDays', [
            'maxlength'    => 2,
            'style'        => 'width: 80px;',
            'defaultValue' => 60,
        ]));

        $checkAr = ['value' => null];
        if (intval($entity->disableIvr) === 1) {
            $checkAr = ['checked' => '1'];
        }
        $this->add(new Check('disableIvr', $checkAr));

        $checkAr = ['value' => null];
        if (intval($entity->simpleMode) === 1) {
            $checkAr = ['checked' => '1'];
        }
        $this->add(new Check('simpleMode', $checkAr));


        // text_area_field
        $this->addTextArea('text_area_field',$entity->text_area_field??'',90,
            ['placeholder' => 'There is placeholder text']
        );

        // password_field
        $this->add(new Password('password_field'));

        // integer_field
        $this->add(new Numeric('integer_field', [
            'maxlength'    => 2,
            'style'        => 'width: 80px;',
            'defaultValue' => 3,
        ]));


        // checkbox_field
        $checkAr = ['value' => null];
        if ($entity->checkbox_field) {
            $checkAr = ['checked' => '1'];
        }
        $this->add(new Check('checkbox_field', $checkAr));

        // toggle_field
        $checkAr = ['value' => null];
        if ($entity->toggle_field) {
            $checkAr = ['checked' => '1'];
        }
        $this->add(new Check('toggle_field', $checkAr));

        // dropdown_field
        $providers = new Select('dropdown_field', $options['providers'], [
            'using'    => [
                'id',
                'name',
            ],
            'useEmpty' => true,
            'class'    => 'ui selection dropdown provider-select',
        ]);
        $this->add($providers);

        $groups = [];
        if(class_exists('\Modules\ModuleUsersGroups\Models\UsersGroups')){
            $result = UsersGroups::find(['columns' => ['id', 'name']]);
            foreach ($result as $group) {
                $groups[$group['id']]  = $group['name'];
            }
        }
        $groupsSelect = new Select('userGroups', $groups, [
            'multiple' => '',
            'data-value-json' => $entity->userGroups,
            'useEmpty' => true,
            'class'    => 'ui selection dropdown',
        ]);
        $this->add($groupsSelect);

        $typesCall = [
            HistoryParser::TYPE_INCOMING => Util::translate('mo_ModuleModuleInterceptionSmartIvr_typeCall_INC'),
            HistoryParser::TYPE_OUTGOING => Util::translate('mo_ModuleModuleInterceptionSmartIvr_typeCall_OUN'),
            HistoryParser::TYPE_ALL.' '      => Util::translate('mo_ModuleModuleInterceptionSmartIvr_typeCall_ALL'),
            HistoryParser::TYPE_ALL.''      => Util::translate('mo_ModuleModuleInterceptionSmartIvr_typeCall_ALL'),
        ];
        $typeCallCdr = new Select('typeCallCdr', $typesCall, [
            'useEmpty' => true,
            'class'    => 'ui selection dropdown',
        ]);
        $this->add($typeCallCdr);
    }
}