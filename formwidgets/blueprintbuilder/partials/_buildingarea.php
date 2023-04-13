<div class="layout-absolute builder-tailor-builder-area">
    <div class="control-scrollpad" data-control="scrollpad">
        <div class="scroll-wrapper">
            <ul class="tailor-blueprint-list" id="blueprintList">
                <?php foreach ($model->blueprints as $blueprintUuid => $blueprintConfig): ?>
                    <?php
                        $model->loadBlueprintInfo($blueprintUuid);
                    ?>
                    <?= $this->makePartial('blueprint', [
                        'blueprintClass' => get_class($model->getLoadedBlueprint()),
                        'blueprintUuid' => $blueprintUuid,
                        'blueprintConfig' => $blueprintConfig
                    ]) ?>
                <?php endforeach ?>
            </ul>
            <div class="add-blueprint-button">
                <a href="javascript:;"
                    data-hotkey="ctrl+i, cmd+i"
                    <?php /*data-builder-command="imports:cmdAddBlueprintItem"*/ ?>
                    data-control="popup"
                    data-handler="<?= $this->getEventHandler('onShowSelectBlueprintForm') ?>"
                >
                    <i class="icon-plus-circle"></i>
                    <span class="title"><?= __("Add Blueprint") ?></span>
                </a>
            </div>
        </div>
    </div>
</div>
