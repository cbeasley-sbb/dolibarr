<?php
/* Copyright (C) 2003-2007 Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2013 Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2011 Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2004      Sebastien Di Cintio     <sdicintio@ressource-toi.org>
 * Copyright (C) 2004      Benoit Mortier          <benoit.mortier@opensides.be>
 * Copyright (C) 2010-2013 Juanjo Menent           <jmenent@2byte.es>
 * Copyright (C) 2011-2018 Philippe Grand          <philippe.grand@atoo-net.com>
 * Copyright (C) 2024-2025	MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024       Frédéric France             <frederic.france@free.fr>
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
 *  \file       htdocs/admin/supplier_invoice.php
 *  \ingroup    fournisseur
 *  \brief      Setup to admin supplier invoices
 */

// Load Dolibarr environment
require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/fourn.lib.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array("admin", "other", "orders"));

$action = GETPOST('action', 'aZ09');

$type = GETPOST('type', 'alpha');
$value = GETPOST('value', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');	// Used by actions_setmoduleoptions.inc.php

$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');

$specimenthirdparty = new Societe($db);
$specimenthirdparty->initAsSpecimen();

$error = 0;

if (!$user->admin) {
	accessforbidden();
}


/*
 * Actions
 */

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if ($action == 'updateMask') {
	$maskconstinvoice = GETPOST('maskconstinvoice', 'aZ09');
	$maskconstcredit = GETPOST('maskconstcredit', 'aZ09');
	$maskconstdeposit = GETPOST('maskconstdeposit', 'aZ09');
	$maskinvoice = GETPOST('maskinvoice', 'alpha');
	$maskcredit = GETPOST('maskcredit', 'alpha');
	$maskdeposit = GETPOST('maskdeposit', 'alpha');
	$res = 0;

	if ($maskconstinvoice && preg_match('/_MASK$/', $maskconstinvoice)) {
		$res = dolibarr_set_const($db, $maskconstinvoice, $maskinvoice, 'chaine', 0, '', $conf->entity);
	}
	if ($maskconstcredit && preg_match('/_MASK$/', $maskconstcredit)) {
		$res = dolibarr_set_const($db, $maskconstcredit, $maskcredit, 'chaine', 0, '', $conf->entity);
	}
	if ($maskconstdeposit && preg_match('/_MASK$/', $maskconstdeposit)) {
		$res = dolibarr_set_const($db, $maskconstdeposit, $maskdeposit, 'chaine', 0, '', $conf->entity);
	}

	if (!($res > 0)) {
		$error++;
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

if ($action == 'specimen') {  // For invoices
	$modele = GETPOST('module', 'alpha');

	$facture = new FactureFournisseur($db);
	$facture->initAsSpecimen();
	$facture->thirdparty = $specimenthirdparty; // Define who should has build the invoice (so the supplier)

	// Search template files
	$file = '';
	$classname = '';
	$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
	foreach ($dirmodels as $reldir) {
		$file = dol_buildpath($reldir."core/modules/supplier_invoice/doc/pdf_".$modele.".modules.php", 0);
		if (file_exists($file)) {
			$classname = "pdf_".$modele;
			break;
		}
	}

	if ($classname !== '') {
		require_once $file;

		$module = new $classname($db, $facture);
		'@phan-var-force ModelePDFSuppliersInvoices $module';

		if ($module->write_file($facture, $langs) > 0) {
			header("Location: ".DOL_URL_ROOT."/document.php?modulepart=facture_fournisseur&file=SPECIMEN.pdf");
			return;
		} else {
			setEventMessages($module->error, $module->errors, 'errors');
			dol_syslog($module->error, LOG_ERR);
		}
	} else {
		setEventMessages($langs->trans("ErrorModuleNotFound"), null, 'errors');
		dol_syslog($langs->trans("ErrorModuleNotFound"), LOG_ERR);
	}
} elseif ($action == 'set') {
	// Activate a model
	$ret = addDocumentModel($value, $type, $label, $scandir);
} elseif ($action == 'del') {
	$ret = delDocumentModel($value, $type);
	if ($ret > 0) {
		if ($conf->global->INVOICE_SUPPLIER_ADDON_PDF == "$value") {
			dolibarr_del_const($db, 'INVOICE_SUPPLIER_ADDON_PDF', $conf->entity);
		}
	}
} elseif ($action == 'setdoc') {
	// Set default model
	if (dolibarr_set_const($db, "INVOICE_SUPPLIER_ADDON_PDF", $value, 'chaine', 0, '', $conf->entity)) {
		// La constante qui a ete lue en avant du nouveau set
		// on passe donc par une variable pour avoir un affichage coherent
		$conf->global->INVOICE_SUPPLIER_ADDON_PDF = $value;
	}

	// On active le modele
	$ret = delDocumentModel($value, $type);
	if ($ret > 0) {
		$ret = addDocumentModel($value, $type, $label, $scandir);
	}
} elseif ($action == 'unsetdoc') {
	dolibarr_del_const($db, "INVOICE_SUPPLIER_ADDON_PDF", $conf->entity);
}

if ($action == 'setmod') {
	// TODO Verify if the chosen numbering module can be activated
	// by calling method canBeActivated

	dolibarr_set_const($db, "INVOICE_SUPPLIER_ADDON_NUMBER", $value, 'chaine', 0, '', $conf->entity);
}

if ($action == 'addcat') {
	$fourn = new Fournisseur($db);
	$fourn->CreateCategory($user, GETPOST('cat', 'alphanohtml'));
}

if ($action == 'set_SUPPLIER_INVOICE_FREE_TEXT') {
	$freetext = GETPOST('SUPPLIER_INVOICE_FREE_TEXT', 'restricthtml'); // No alpha here, we want exact string

	$res = dolibarr_set_const($db, "SUPPLIER_INVOICE_FREE_TEXT", $freetext, 'chaine', 0, '', $conf->entity);

	if (!($res > 0)) {
		$error++;
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}


/*
 * View
 */

$form = new Form($db);

$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);

llxHeader('', '', '', '', 0, 0, '', '', '', 'mod-admin page-supplier_invoice');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("SuppliersSetup"), $linkback, 'title_setup');

print "<br>";

$head = supplierorder_admin_prepare_head();

print dol_get_fiche_head($head, 'invoice', $langs->trans("Suppliers"), -1, 'company');


// Supplier invoice numbering module

print load_fiche_titre($langs->trans("SuppliersInvoiceNumberingModel"), '', '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td width="100">'.$langs->trans("Name").'</td>';
print '<td>'.$langs->trans("Description").'</td>';
print '<td>'.$langs->trans("Example").'</td>';
print '<td align="center" width="60">'.$langs->trans("Status").'</td>';
print '<td align="center" width="16">'.$langs->trans("ShortInfo").'</td>';
print "</tr>\n";

clearstatcache();

foreach ($dirmodels as $reldir) {
	$dir = dol_buildpath($reldir."core/modules/supplier_invoice");

	if (is_dir($dir)) {
		$handle = opendir($dir);
		if (is_resource($handle)) {
			while (($file = readdir($handle)) !== false) {
				if (substr($file, 0, 24) == 'mod_facture_fournisseur_' && substr($file, dol_strlen($file) - 3, 3) == 'php') {
					$file = substr($file, 0, dol_strlen($file) - 4);

					require_once $dir.'/'.$file.'.php';

					$module = new $file();

					'@phan-var-force ModeleNumRefSuppliersInvoices $module';

					if ($module->isEnabled()) {
						// Show modules according to features level
						if ($module->version == 'development' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 2) {
							continue;
						}
						if ($module->version == 'experimental' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 1) {
							continue;
						}


						print '<tr class="oddeven"><td>'.$module->getName($langs)."</td><td>\n";
						print $module->info($langs);
						print '</td>';

						// Show example of numbering module
						print '<td class="nowrap">';
						$tmp = $module->getExample();
						if (preg_match('/^Error/', $tmp)) {
							$langs->load("errors");
							print '<div class="error">'.$langs->trans($tmp).'</div>';
						} elseif ($tmp == 'NotConfigured') {
							print '<span class="opacitymedium">'.$langs->trans($tmp).'</span>';
						} else {
							print $tmp;
						}
						print '</td>'."\n";

						print '<td class="center">';
						if ($conf->global->INVOICE_SUPPLIER_ADDON_NUMBER == "$file") {
							print img_picto($langs->trans("Activated"), 'switch_on');
						} else {
							print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=setmod&token='.newToken().'&value='.urlencode($file).'" alt="'.$langs->trans("Default").'">'.img_picto($langs->trans("Disabled"), 'switch_off').'</a>';
						}
						print '</td>';

						$invoice = new FactureFournisseur($db);
						$invoice->initAsSpecimen();

						// Info
						$htmltooltip = '';
						$htmltooltip .= ''.$langs->trans("Version").': <b>'.$module->getVersion().'</b><br>';
						$nextval = $module->getNextValue($mysoc, $invoice);
						if ("$nextval" != $langs->trans("NotAvailable")) {  // Keep " on nextval
							$htmltooltip .= ''.$langs->trans("NextValue").': ';
							if ($nextval) {
								if (preg_match('/^Error/', $nextval) || $nextval == 'NotConfigured') {
									$nextval = $langs->trans($nextval);
								}
								$htmltooltip .= $nextval.'<br>';
							} else {
								$htmltooltip .= $langs->trans($module->error).'<br>';
							}
						}

						print '<td class="center">';
						print $form->textwithpicto('', $htmltooltip, 1, 'info');
						print '</td>';

						print '</tr>';
					}
				}
			}
			closedir($handle);
		}
	}
}

print '</table></div><br>';




/*
 *   Documents models for supplier invoices
 */

print load_fiche_titre($langs->trans("BillsPDFModules"), '', '');

// Defini tableau def de modele
$def = array();

$sql = "SELECT nom";
$sql .= " FROM ".MAIN_DB_PREFIX."document_model";
$sql .= " WHERE type = 'invoice_supplier'";
$sql .= " AND entity = ".$conf->entity;

$resql = $db->query($sql);
if ($resql) {
	$i = 0;
	$num_rows = $db->num_rows($resql);
	while ($i < $num_rows) {
		$array = $db->fetch_array($resql);
		if (is_array($array)) {
			array_push($def, $array[0]);
		}
		$i++;
	}
} else {
	dol_print_error($db);
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">'."\n";
print '<tr class="liste_titre">'."\n";
print '<td width="100">'.$langs->trans("Name").'</td>'."\n";
print '<td>'.$langs->trans("Description").'</td>'."\n";
print '<td align="center" width="60">'.$langs->trans("Status").'</td>'."\n";
print '<td align="center" width="60">'.$langs->trans("Default").'</td>'."\n";
print '<td align="center" width="40">'.$langs->trans("ShortInfo").'</td>';
print '<td align="center" width="40">'.$langs->trans("Preview").'</td>';
print '</tr>'."\n";

clearstatcache();

foreach ($dirmodels as $reldir) {
	$realpath = $reldir."core/modules/supplier_invoice/doc";
	$dir = dol_buildpath($realpath);

	if (is_dir($dir)) {
		$handle = opendir($dir);


		if (is_resource($handle)) {
			while (($file = readdir($handle)) !== false) {
				if (preg_match('/\.modules\.php$/i', $file) && preg_match('/^(pdf_|doc_)/', $file)) {
					$name = substr($file, 4, dol_strlen($file) - 16);
					$classname = substr($file, 0, dol_strlen($file) - 12);

					require_once $dir.'/'.$file;
					$module = new $classname($db, new FactureFournisseur($db));

					'@phan-var-force ModelePDFSuppliersInvoices $module';


					print "<tr class=\"oddeven\">\n";
					print "<td>";
					print(empty($module->name) ? $name : $module->name);
					print "</td>\n";
					print "<td>\n";
					require_once $dir.'/'.$file;
					$module = new $classname($db, $specimenthirdparty);
					'@phan-var-force ModelePDFSuppliersInvoices $module';
					if (method_exists($module, 'info')) {
						print $module->info($langs);  // @phan-suppress-current-line PhanUndeclaredMethod
					} else {
						print $module->description;
					}

					print "</td>\n";

					// Active
					if (in_array($name, $def)) {
						print '<td class="center">'."\n";
						//if ($conf->global->INVOICE_SUPPLIER_ADDON_PDF != "$name")
						//{
						// Even if choice is the default value, we allow to disable it: For supplier invoice, we accept to have no doc generation at all
						print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=del&token='.newToken().'&value='.urlencode($name).'&scan_dir='.urlencode($module->scandir).'&label='.urlencode($module->name).'&amp;type=invoice_supplier">';
						print img_picto($langs->trans("Enabled"), 'switch_on');
						print '</a>';
						/*}
						else
						{
							print img_picto($langs->trans("Enabled"),'switch_on');
						}*/
						print "</td>";
					} else {
						print '<td class="center">'."\n";
						print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=set&token='.newToken().'&value='.urlencode($name).'&scan_dir='.urlencode($module->scandir).'&label='.urlencode($module->name).'&amp;type=invoice_supplier">'.img_picto($langs->trans("Disabled"), 'switch_off').'</a>';
						print "</td>";
					}

					// Default
					print '<td class="center">';
					if (getDolGlobalString("INVOICE_SUPPLIER_ADDON_PDF") == "$name") {
						//print img_picto($langs->trans("Default"),'on');
						// Even if choice is the default value, we allow to disable it: For supplier invoice, we accept to have no doc generation at all
						print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=unsetdoc&token='.newToken().'&value='.$name.'&scan_dir='.$module->scandir.'&label='.urlencode($module->name).'&type=invoice_supplier"" alt="'.$langs->trans("Disable").'">'.img_picto($langs->trans("Enabled"), 'on').'</a>';
					} else {
						print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=setdoc&token='.newToken().'&value='.$name.'&scan_dir='.$module->scandir.'&label='.urlencode($module->name).'&type=invoice_supplier"" alt="'.$langs->trans("Default").'">'.img_picto($langs->trans("Disabled"), 'off').'</a>';
					}
					print '</td>';

					// Info
					$htmltooltip = ''.$langs->trans("Name").': '.$module->name;
					$htmltooltip .= '<br>'.$langs->trans("Type").': '.($module->type ? $module->type : $langs->trans("Unknown"));
					$htmltooltip .= '<br>'.$langs->trans("Width").'/'.$langs->trans("Height").': '.$module->page_largeur.'/'.$module->page_hauteur;
					$htmltooltip .= '<br>'.$langs->trans("Path").': '.preg_replace('/^\//', '', $realpath).'/'.$file;

					$htmltooltip .= '<br><br><u>'.$langs->trans("FeaturesSupported").':</u>';
					$htmltooltip .= '<br>'.$langs->trans("Logo").': '.yn($module->option_logo, 1, 1);
					$htmltooltip .= '<br>'.$langs->trans("PaymentMode").': '.yn($module->option_modereg, 1, 1);
					$htmltooltip .= '<br>'.$langs->trans("PaymentConditions").': '.yn($module->option_condreg, 1, 1);
					print '<td class="center">';
					print $form->textwithpicto('', $htmltooltip, 1, 'info');
					print '</td>';
					print '<td class="center">';
					print '<a href="'.$_SERVER["PHP_SELF"].'?action=specimen&module='.urlencode($name).'">'.img_object($langs->trans("Preview"), 'pdf').'</a>';
					print '</td>';

					print "</tr>\n";
				}
			}

			closedir($handle);
		}
	}
}

print '</table></div><br>';

/*
 * Other options
 */

print '<form action="'.$_SERVER["PHP_SELF"].'" method="post">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="set_SUPPLIER_INVOICE_FREE_TEXT">';

print load_fiche_titre($langs->trans("OtherOptions"), '', '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td align="center" width="60"></td>';
print '<td width="80">&nbsp;</td>';
print "</tr>\n";

$substitutionarray = pdf_getSubstitutionArray($langs, null, null, 2);
$substitutionarray['__(AnyTranslationKey)__'] = $langs->trans("Translation");
$htmltext = '<i>'.$langs->trans("AvailableVariables").':<br>';
foreach ($substitutionarray as $key => $val) {
	$htmltext .= $key.'<br>';
}
$htmltext .= '</i>';

print '<tr class="oddeven"><td colspan="2">';
print $form->textwithpicto($langs->trans("FreeLegalTextOnInvoices"), $langs->trans("AddCRIfTooLong").'<br><br>'.$htmltext, 1, 'help', '', 0, 2, 'freetexttooltip').'<br>';
$variablename = 'SUPPLIER_INVOICE_FREE_TEXT';
if (!getDolGlobalString('PDF_ALLOW_HTML_FOR_FREE_TEXT')) {
	print '<textarea name="'.$variablename.'" class="flat" cols="120">'.getDolGlobalString($variablename).'</textarea>';
} else {
	include_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
	$doleditor = new DolEditor($variablename, getDolGlobalString($variablename), '', 80, 'dolibarr_notes');
	print $doleditor->Create();
}
print '</td><td class="right">';
print '<input type="submit" class="button button-edit" value="'.$langs->trans("Modify").'">';
print "</td></tr>\n";

print '</table></div><br>';

print '</form>';


/*
 * Notifications
 */

print load_fiche_titre($langs->trans("Notifications"), '', '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td align="center" width="60"></td>';
print '<td width="80">&nbsp;</td>';
print "</tr>\n";

print '<tr class="oddeven"><td colspan="2">';
print $langs->trans("YouMayFindNotificationsFeaturesIntoModuleNotification").'<br>';
print '</td><td class="right">';
print "</td></tr>\n";

print '</table>';
print '</div>';

// End of page
llxFooter();
$db->close();
