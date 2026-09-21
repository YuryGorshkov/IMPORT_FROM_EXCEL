<?php

?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="webenot.importexcel">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <label>
        <input type="checkbox" name="savedata" value="Y" checked>
        Сохранить профили, задания и журнал импорта
    </label>
    <br><br>
    <input type="submit" value="Продолжить">
</form>
