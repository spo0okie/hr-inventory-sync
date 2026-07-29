<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();
/*
 * @var array $arResult
 * @var array $arParams
 * @var CBitrixComponentTemplate $this
 */

if (!count($arResult['ITEMS'])) {
	?><div class="sapsync-absence sapsync-absence--empty">Отсутствий не запланировано</div><?php
	return;
}
?>
<div class="sapsync-absence">
	<?php foreach ($arResult['ITEMS'] as $item): ?>
		<div class="sapsync-absence__item<?= $item['CURRENT'] ? ' sapsync-absence__item--current' : '' ?>">
			<span class="sapsync-absence__mark sapsync-absence__mark--<?= strtolower(htmlspecialcharsbx($item['TYPE_CODE'])) ?>"></span>
			<span class="sapsync-absence__name"><?= htmlspecialcharsbx($item['NAME']) ?></span>
			<span class="sapsync-absence__dates">
				<?php
				//год показываем только если отсутствие не в текущем году
				$fmt = (date('Y', $item['FROM']) == date('Y')) ? 'j F' : 'j F Y';
				echo FormatDate($fmt, $item['FROM']);
				if (date('d.m.Y', $item['FROM']) !== date('d.m.Y', $item['TO'])) {
					$fmtTo = (date('Y', $item['TO']) == date('Y')) ? 'j F' : 'j F Y';
					echo ' — ' . FormatDate($fmtTo, $item['TO']);
				}
				?>
			</span>
			<?php if ($item['CURRENT']): ?>
				<span class="sapsync-absence__now">сейчас</span>
			<?php endif ?>
		</div>
	<?php endforeach ?>
</div>
