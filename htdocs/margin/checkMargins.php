<?php
/* Copyright (C) 2012-2013	Christophe Battarel	<christophe.battarel@altairis.fr>
 * Copyright (C) 2014		Ferran Marcet		<fmarcet@2byte.es>
 * Copyright (C) 2015       Marcos García       <marcosgdf@gmail.com>
 * Copyright (C) 2016       Florian Henry       <florian.henry@open-concept.pro>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file 	htdocs/margin/checkMargins.php
 * \ingroup margin
 * \brief 	Check margins
 */

require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/margin/lib/margins.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array('companies', 'bills', 'products', 'margins'));

$action     = GETPOST('action', 'alpha');
$massaction = GETPOST('massaction', 'alpha');
$toselect   = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'margindetail'; // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha');
$optioncss  = GETPOST('optioncss', 'alpha');

// Load variable for pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page == -1) {
	$page = 0;
}     // If $page is not defined, or '' or -1
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortorder) {
	$sortorder = "DESC";
}
if (!$sortfield) {
	$sortfield = 'f.ref';
}

$startdate = $enddate = '';

$startdate = dol_mktime(0, 0, 0, GETPOSTINT('startdatemonth'), GETPOSTINT('startdateday'), GETPOSTINT('startdateyear'));
$enddate = dol_mktime(23, 59, 59, GETPOSTINT('enddatemonth'), GETPOSTINT('enddateday'), GETPOSTINT('enddateyear'));

$search_ref = GETPOST('search_ref', 'alpha');

$hookmanager->initHooks(array('checkmarginlist'));

// Security check
$result = restrictedArea($user, 'margins');

// Both test are required to be compatible with all browsers
if (GETPOST("button_search_x") || GETPOST("button_search")) {
	$action = 'search';
} elseif (GETPOST("button_updatemagins_x") || GETPOST("button_updatemagins")) {
	$action = 'update';
}

$permissiontocreate = $user->hasRight('facture', 'creer');


/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) {
	$action = 'list';
	$massaction = '';
}
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
	$massaction = '';
}

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	// Selection of new fields
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';

	if ($action == 'update' && $permissiontocreate) {
		$datapost = $_POST;

		foreach ($datapost as $key => $value) {
			if (strpos($key, 'buyingprice_') !== false) {
				$tmp_array = explode('_', $key);
				if (count($tmp_array) > 0) {
					$invoicedet_id = $tmp_array[1];
					if (!empty($invoicedet_id)) {
						$sql = 'UPDATE '.MAIN_DB_PREFIX.'facturedet';
						$sql .= " SET buy_price_ht = ".((float) price2num($value));
						$sql .= ' WHERE rowid = '.((int) $invoicedet_id);
						$result = $db->query($sql);
						if (!$result) {
							setEventMessages($db->lasterror, null, 'errors');
						}
					}
				}
			}
		}
	}

	// Purge search criteria
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All tests are required to be compatible with all browsers
		$search_ref = '';
		$search_array_options = array();
	}

	// Mass actions
	/*
	$objectclass='Product';
	if ((string) $type == '1') { $objectlabel='Services'; }
	if ((string) $type == '0') { $objectlabel='Products'; }

	$permissiontoread = $user->rights->produit->lire;
	$permissiontodelete = $user->rights->produit->supprimer;
	$uploaddir = $conf->product->dir_output;
	include DOL_DOCUMENT_ROOT.'/core/actions_massactions.inc.php';
	*/
}


/*
 * View
 */

$userstatic = new User($db);
$companystatic = new Societe($db);
$invoicestatic = new Facture($db);
$productstatic = new Product($db);

$form = new Form($db);

$title = $langs->trans("MarginDetails");

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-margin page-checkmargins');

// print load_fiche_titre($text);

$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
	$param .= '&contextpage='.$contextpage;
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.$limit;
}
if ($search_ref != '') {
	$param .= '&search_ref='.urlencode($search_ref);
}
if (!empty($startdate)) {
	$param .= '&startdatemonth='.GETPOSTINT('startdatemonth').'&startdateday='.GETPOSTINT('startdateday').'&startdateyear='.GETPOSTINT('startdateyear');
}
if (!empty($enddate)) {
	$param .= '&enddatemonth='.GETPOSTINT('enddatemonth').'&enddateday='.GETPOSTINT('enddateday').'&enddateyear='.GETPOSTINT('enddateyear');
}
if ($optioncss != '') {
	$param .= '&optioncss='.$optioncss;
}

// Show tabs
$head = marges_prepare_head();

$picto = 'margin';

print '<form method="post" name="sel" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print dol_get_fiche_head($head, $langs->trans('checkMargins'), $title, 0, $picto);

print '<table class="border centpercent">';

print '<tr><td class="titlefield">'.$langs->trans('DateStart').' ('.$langs->trans("DateValidation").')</td>';
print '<td>';
print $form->selectDate($startdate, 'startdate', 0, 0, 1, "sel", 1, 1);
print '</td>';
print '<td>'.$langs->trans('DateEnd').' ('.$langs->trans("DateValidation").')</td>';
print '<td>';
print $form->selectDate($enddate, 'enddate', 0, 0, 1, "sel", 1, 1);
print '</td>';
print '<td style="text-align: center;">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Refresh')).'" name="button_search" />';
print '</td>';
print '</tr>';
print "</table>";

print dol_get_fiche_end();


$arrayfields = array();
$massactionbutton = '';

$invoice_status_except_list = array(Facture::STATUS_DRAFT, Facture::STATUS_ABANDONED);

$sql = "SELECT";
$sql .= " f.ref, f.rowid as invoiceid,";
$sql .= " d.rowid as invoicedetid, d.product_type, d.buy_price_ht, d.total_ht, d.subprice, d.label, d.description, d.qty, d.fk_product";
$sql .= " FROM ".MAIN_DB_PREFIX."facture as f ";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facturedet as d ON d.fk_facture = f.rowid";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON d.fk_product = p.rowid";
$sql .= " WHERE f.fk_statut NOT IN (".$db->sanitize(implode(', ', $invoice_status_except_list)).")";
$sql .= " AND f.entity IN (".getEntity('invoice').") ";
if (!empty($startdate)) {
	$sql .= " AND f.datef >= '".$db->idate($startdate)."'";
}
if (!empty($enddate)) {
	$sql .= " AND f.datef <= '".$db->idate($enddate)."'";
}
if ($search_ref) {
	$sql .= natural_search('f.ref', $search_ref);
}
$sql .= " AND d.buy_price_ht IS NOT NULL";
$sql .= $db->order($sortfield, $sortorder);

$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	dol_syslog(__FILE__, LOG_DEBUG);
	$result = $db->query($sql);
	$nbtotalofrecords = $db->num_rows($result);
	if (($page * $limit) > $nbtotalofrecords) {	// if total resultset is smaller then paging size (filtering), goto and load page 0
		$page = 0;
		$offset = 0;
	}
}

$sql .= $db->plimit($limit + 1, $offset);

$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);

	print '<br>';
	// @phan-suppress-next-line PhanPluginSuspiciousParamOrder
	print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, '', 0, '', '', $limit, 0, 0, 1);

	if (getDolGlobalString('MARGIN_TYPE') == "1") {
		$labelcostprice = 'BuyingPrice';
	} else { // value is 'costprice' or 'pmp'
		$labelcostprice = 'CostPrice';
	}

	$moreforfilter = '';

	$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;
	//$selectedfields=$form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage);	// This also change content of $arrayfields
	//if ($massactionbutton) $selectedfields.=$form->showCheckAddButtons('checkforselect', 1);
	$selectedfields = '';

	print '<div class="div-table-responsive">';
	print '<table class="tagtable noborder nobottomiftotal liste'.($moreforfilter ? " listwithfilterbefore" : "").'">'."\n";

	print '<tr class="liste_titre liste_titre_search">';
	if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
		print '<td class="liste_titre center">';
		$searchpitco = $form->showFilterButtons();
		print $searchpitco;
		print '</td>';
	}
	print '<td><input type="text" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
	print '<td></td>';
	print '<td></td>';
	print '<td></td>';
	print '<td></td>';
	print '<td></td>';
	if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
		print '<td class="liste_titre center">';
		$searchpitco = $form->showFilterButtons();
		print $searchpitco;
		print '</td>';
	}
	print "</tr>\n";

	print '<tr class="liste_titre">';
	if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
		print_liste_field_titre($selectedfields, $_SERVER["PHP_SELF"], "", '', $param, '', $sortfield, $sortorder, 'maxwidthsearch center ');
	}
	print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "f.ref", "", $param, '', $sortfield, $sortorder);
	print_liste_field_titre("Description", $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder);
	print_liste_field_titre("UnitPriceHT", $_SERVER["PHP_SELF"], "d.subprice", "", $param, '', $sortfield, $sortorder, 'right ');
	print_liste_field_titre($labelcostprice, $_SERVER["PHP_SELF"], "d.buy_price_ht", "", $param, '', $sortfield, $sortorder, 'right ');
	print_liste_field_titre("Qty", $_SERVER["PHP_SELF"], "d.qty", "", $param, '', $sortfield, $sortorder, 'right ');
	print_liste_field_titre("AmountTTC", $_SERVER["PHP_SELF"], "d.total_ht", "", $param, '', $sortfield, $sortorder, 'right ');
	if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
		print_liste_field_titre($selectedfields, $_SERVER["PHP_SELF"], "", '', $param, '', $sortfield, $sortorder, 'maxwidthsearch center ');
	}
	print "</tr>\n";

	$i = 0;
	while ($i < min($num, $limit)) {
		$objp = $db->fetch_object($result);

		print '<tr class="oddeven">';

		if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
			print '<td></td>';
		}

		print '<td>';
		$result_inner = $invoicestatic->fetch($objp->invoiceid);
		if ($result_inner < 0) {
			setEventMessages($invoicestatic->error, null, 'errors');
		} else {
			print $invoicestatic->getNomUrl(1);
		}
		print '</td>';
		print '<td>';
		if (!empty($objp->fk_product)) {
			$result_inner = $productstatic->fetch($objp->fk_product);
			if ($result_inner < 0) {
				setEventMessages($productstatic->error, null, 'errors');
			} else {
				print $productstatic->getNomUrl(1);
			}
		} else {
			if ($objp->product_type == $productstatic::TYPE_PRODUCT) {
				print img_picto('', 'product');
			}
			if ($objp->product_type == $productstatic::TYPE_SERVICE) {
				print img_picto('', 'service');
			}
			print $objp->label;
			print '&nbsp;';
			print $objp->description;
		}
		print '</td>';
		print '<td class="right">';
		print price($objp->subprice);
		print '</td>';
		print '<td class="right">';
		print '<input type="text" name="buyingprice_'.$objp->invoicedetid.'" id="buyingprice_'.$objp->invoicedetid.'" size="6" value="'.price($objp->buy_price_ht).'" class="right flat">';
		print '</td>';
		print '<td class="right">';
		print $objp->qty;
		print '</td>';
		print '<td class="right">';
		print '<span class="amount">'.price($objp->total_ht).'</span>';
		print '</td>';

		if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
			print '<td></td>';
		}

		print "</tr>\n";

		$i++;
	}

	if ($num == 0) {
		print '<tr class=""><td colspan="7">';
		print '<span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span>';
		print '</td></tr>';
	}

	print "</table>";

	print "</div>";
} else {
	dol_print_error($db);
}


print '<div class="center">'."\n";
print '<input type="submit" class="button" name="button_updatemagins" id="button_updatemagins" value="'.$langs->trans("Update").'">';
print '</div>';

print '</form>';

$db->free($result);

// End of page
llxFooter();
$db->close();
