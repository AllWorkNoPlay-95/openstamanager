<?php

/*
 * OpenSTAManager: il software gestionale open source per l'assistenza tecnica e la fatturazione
 * Copyright (C) DevCode s.r.l.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

// Modulo custom MNCS: avviso su dove configurare l'abbuono automatico degli incassi.
echo '
<div class="alert alert-info">
    <i class="fa fa-info-circle"></i>
    '.tr('Le impostazioni _CONTO_ e _SOGLIA_ per l\'abbuono automatico in fase di incasso si trovano in _PERCORSO_.', [
        '_CONTO_' => '<b>'.tr('Conto abbuono').'</b>',
        '_SOGLIA_' => '<b>'.tr('Soglia abbuono').'</b>',
        '_PERCORSO_' => '<b>'.tr('Impostazioni').' &raquo; '.tr('Fatturazione').'</b>',
    ]).'
</div>';
