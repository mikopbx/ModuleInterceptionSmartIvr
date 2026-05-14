<form class="ui grey form" id="module-interception-smart-ivr-form">
    {{ form.render('id') }}

    <div class="field">
        <label>{{ t._('mo_ModuleModuleInterceptionSmartIvr_cdrCountH') }}</label>
        {{ form.render('cdrCountDays') }}
    </div>
    <div class="field">
        <label>{{ t._('mo_ModuleModuleInterceptionSmartIvr_interceptionDialDuration') }}</label>
        {{ form.render('interceptionDialDuration') }}
    </div>
    <div class="field disability">
        <div class="ui toggle checkbox">
            <label>{{ t._('mo_ModuleModuleInterceptionSmartIvr_simpleMode') }}</label>
            {{ form.render('simpleMode') }}
        </div>
    </div>
    <div class="ten wide field">
        <label >{{ t._('mo_ModuleModuleInterceptionSmartIvr_userGroups') }}</label>
        {{ form.render('userGroups') }}
    </div>

    <div class="ten wide field">
        <label >{{ t._('mo_ModuleModuleInterceptionSmartIvr_typeCall') }}</label>
        {{ form.render('typeCallCdr') }}
    </div>

    <div id="notSimpleOptions">
        <div class="field disability">
            <div class="ui toggle checkbox">
                <label>{{ t._('mo_ModuleModuleInterceptionSmartIvr_disableIvr') }}</label>
                {{ form.render('disableIvr') }}
            </div>
        </div>
        <div id="ivr-options">
            <div class="ten wide field disability">
                <label >{{ t._('mo_ModuleModuleInterceptionSmartIvr_yandexApiKey') }}</label>
                {{ form.render('yandexApiKey') }}
            </div>

            <div class="ten wide field disability">
                <label >{{ t._('mo_ModuleModuleInterceptionSmartIvr_textIvr') }}</label>
                {{ form.render('textIvr') }}
            </div>

            <div class="ten wide field disability">
                <label >{{ t._('mo_ModuleModuleInterceptionSmartIvr_textInvalidNumber') }}</label>
                {{ form.render('textInvalidNumber') }}
            </div>

            <div class="ten wide field disability">
                <label >{{ t._('mo_ModuleModuleInterceptionSmartIvr_textNumberBusy') }}</label>
                {{ form.render('textNumberBusy') }}
            </div>

            <div class="field">
                <label>{{ t._('mo_ModuleModuleInterceptionSmartIvr_FailoverExtension') }}</label>
                {{ form.render('failover_extension') }}
            </div>
            <div class="field">
                <label>{{ t._('mo_ModuleModuleInterceptionSmartIvr_timeoutInterception') }}</label>
                {{ form.render('timeoutInterception') }}
            </div>
        </div>
    </div>
    <br>
   {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index']) }}
</form>