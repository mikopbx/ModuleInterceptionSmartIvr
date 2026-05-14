"use strict";

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

/* global globalRootUrl, globalTranslate, Form, Config */
var ModuleInterceptionSmartIvrModify = {
  $formObj: $('#module-interception-smart-ivr-form'),
  $checkBoxes: $('#module-interception-smart-ivr-form .ui.checkbox'),
  $dropDowns: $('#module-interception-smart-ivr-form .ui.dropdown'),

  /**
   * Field validation rules
   * https://semantic-ui.com/behaviors/form.html
   */
  validateRules: {
    textField: {
      identifier: 'text_field',
      rules: [{
        type: 'empty',
        prompt: globalTranslate.module_template_ValidateValueIsEmpty
      }]
    },
    areaField: {
      identifier: 'text_area_field',
      rules: [{
        type: 'empty',
        prompt: globalTranslate.module_template_ValidateValueIsEmpty
      }]
    },
    passwordField: {
      identifier: 'password_field',
      rules: [{
        type: 'empty',
        prompt: globalTranslate.module_template_ValidateValueIsEmpty
      }]
    }
  },

  /**
   * On page load init some Semantic UI library
   */
  initialize: function initialize() {
    ModuleInterceptionSmartIvrModify.$checkBoxes.checkbox({
      onChange: ModuleInterceptionSmartIvrModify.onChangeCheckbox
    });
    ModuleInterceptionSmartIvrModify.$dropDowns.dropdown();
    var $userGroup = $('#userGroups');
    var groupData = $userGroup.attr('data-value-json');

    if (groupData !== "") {
      $userGroup.dropdown('set selected', JSON.parse(groupData));
    }

    ModuleInterceptionSmartIvrModify.initializeForm();
    ModuleInterceptionSmartIvrModify.onChangeCheckbox();
  },

  /**
   * При выключении всех чекбоксов отключить кнопку
   */
  onChangeCheckbox: function onChangeCheckbox() {
    var formResult = ModuleInterceptionSmartIvrModify.$formObj.form('get values');
    console.log('formResult', formResult);

    if (formResult.disableIvr === 'on' || formResult.disableIvr === true || formResult.disableIvr === "1") {
      $('#ivr-options').hide();
    } else {
      $('#ivr-options').show();
    }

    if (formResult.simpleMode === 'on' || formResult.simpleMode === true || formResult.simpleMode === "1") {
      $('#notSimpleOptions').hide();
    } else {
      $('#notSimpleOptions').show();
    }
  },

  /**
   * We can modify some data before form send
   * @param settings
   * @returns {*}
   */
  cbBeforeSendForm: function cbBeforeSendForm(settings) {
    var result = settings;
    result.data = ModuleInterceptionSmartIvrModify.$formObj.form('get values');
    return result;
  },

  /**
   * Some actions after forms send
   */
  cbAfterSendForm: function cbAfterSendForm() {},

  /**
   * Initialize form parameters
   */
  initializeForm: function initializeForm() {
    Form.$formObj = ModuleInterceptionSmartIvrModify.$formObj;
    Form.url = "".concat(globalRootUrl, "module-interception-smart-ivr/module-interception-smart-ivr/save");
    Form.validateRules = ModuleInterceptionSmartIvrModify.validateRules;
    Form.cbBeforeSendForm = ModuleInterceptionSmartIvrModify.cbBeforeSendForm;
    Form.cbAfterSendForm = ModuleInterceptionSmartIvrModify.cbAfterSendForm;
    Form.initialize();
  }
};
$(document).ready(function () {
  ModuleInterceptionSmartIvrModify.initialize();
});
//# sourceMappingURL=module-interception-smart-ivr-modify.js.map