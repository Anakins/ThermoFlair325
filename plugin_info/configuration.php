<?php
if (!isConnect('admin')) {
    throw new Exception('401 Unauthorized');
}
?>
<form class="form-horizontal">
    <fieldset>
        <div class="alert alert-info" style="margin:10px;">
            <i class="fas fa-info-circle"></i>
            {{Tous les réglages se font dans l'équipement (menu Plugins → Énergie → ThermoFlair 325) : un seul équipement suffit pour toute la maison.}}
            <br>{{Le plugin tourne chaque minute (tâche « cron » du plugin) : vérifiez qu'elle est bien active dans la section « Fonctionnalités » ci-dessus.}}
        </div>
    </fieldset>
</form>
