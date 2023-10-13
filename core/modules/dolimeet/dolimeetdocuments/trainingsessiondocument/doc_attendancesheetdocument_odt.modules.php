<?php
/* Copyright (C) 2021-2023 EVARISK <technique@evarisk.com>
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
 * or see https://www.gnu.org/
 */

/**
 * \file    core/modules/dolimeet/dolimeetdocuments/trainingsessiondocument/doc_attendancesheetdocument_odt.modules.php
 * \ingroup dolimeet
 * \brief   File of class to build ODT attendancesheet document.
 */

// Load Dolibarr libraries.
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/images.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';

// Load Saturne libraries.
require_once __DIR__ . '/../../../../../../saturne/class/saturnesignature.class.php';
require_once __DIR__ . '/../../../../../../saturne/core/modules/saturne/modules_saturne.php';

// Load DoliMeet libraries.
require_once __DIR__ . '/../attendancesheetdocument/mod_attendancesheetdocument_standard.php';

/**
 * Class to build documents using ODF templates generator.
 */
class doc_attendancesheetdocument_odt extends SaturneDocumentModel
{
    /**
     * @var array Minimum version of PHP required by module.
     * e.g.: PHP ≥ 5.5 = array(5, 5)
     */
    public $phpmin = [7, 4];

    /**
     * @var string Dolibarr version of the loaded document.
     */
    public string $version = 'dolibarr';

    /**
     * @var string Module.
     */
    public string $module = 'dolimeet';

    /**
     * @var string Document type.
     */
    public string $document_type = 'attendancesheetdocument';

    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler.
     */
    public function __construct(DoliDB $db)
    {
        parent::__construct($db, $this->module, $this->document_type);
    }

    /**
     * Return description of a module.
     *
     * @param  Translate $langs Lang object to use for output.
     * @return string           Description.
     */
    public function info(Translate $langs): string
    {
        return parent::info($langs);
    }

    /**
     * Set attendants segment.
     *
     * @param  Odf       $odfHandler  Object builder odf library.
     * @param  Translate $outputLangs Lang object to use for output.
     * @param  array     $moreParam   More param (Object/user/etc).
     *
     * @throws Exception
     */
    public function setAttendantsSegment(Odf $odfHandler, Translate $outputLangs, array $moreParam)
    {
        global $conf, $moduleNameLowerCase, $langs;

        $signatoryRoles = [];
        if (!empty($moreParam['object'])) {
            $signatory        = new SaturneSignature($this->db, $this->module, $moreParam['object']->element);
            $signatoriesArray = $signatory->fetchSignatories($moreParam['object']->id, $moreParam['object']->element);
            if (!empty($moreParam['multipleAttendantsRole'] && !empty($signatoriesArray) && is_array($signatoriesArray))) {
                foreach($signatoriesArray as $signatory) {
                    if (!array_key_exists($signatory->role, $signatoryRoles)) {
                        $signatoryRoles[$signatory->role] = [];
                    }
                    $signatoryRoles[$signatory->role][] = $signatory;
                }
            } else {
                $signatoryRoles = ['attendants'];
            }

            $moreParam['excludeAttendantsRole'] = (empty($moreParam['excludeAttendantsRole']) ? [] : $moreParam['excludeAttendantsRole']);

            foreach($signatoryRoles as $role => $signatoryObject) {
                if (!in_array($role, $moreParam['excludeAttendantsRole'])) {
                    // Get attendants.
                    $role             = dol_strtolower($role);
                    $foundTagForLines = 1;
                    try {
                        $listLines = $odfHandler->setSegment($role);
                    } catch (OdfException $e) {
                        // We may arrive here if tags for lines not present into template.
                        $foundTagForLines = 0;
                        $listLines        = '';
                        dol_syslog($e->getMessage());
                    }

                    if ($foundTagForLines) {
                        $nbAttendant = 0;
                        $tempDir     = $conf->$moduleNameLowerCase->multidir_output[$moreParam['object']->entity ?? 1] . '/temp/';
                        if (!empty($signatoryObject) && is_array($signatoryObject)) {
                            foreach ($signatoryObject as $objectSignatory) {
                                $tmpArray[$role . '_number']    = ++$nbAttendant;
                                $tmpArray[$role . '_lastname']  = strtoupper($objectSignatory->lastname);
                                $tmpArray[$role . '_firstname'] = dol_strlen($objectSignatory->firstname) > 0 ? ucfirst($objectSignatory->firstname) : '';
                                switch ($objectSignatory->attendance) {
                                    case 1:
                                        $attendance = $outputLangs->trans('Delay');
                                        break;
                                    case 2:
                                        $attendance = $outputLangs->trans('Absent');
                                        break;
                                    default:
                                        $attendance = $outputLangs->transnoentities('Present');
                                        break;
                                }
                                switch ($objectSignatory->element_type) {
                                    case 'user':
                                        $user    = new User($this->db);
                                        $societe = new Societe($this->db);
                                        $user->fetch($objectSignatory->element_id);
                                        $tmpArray[$role . '_job'] = $user->job;
                                        if ($user->fk_soc > 0) {
                                            $societe->fetch($user->fk_soc);
                                            $tmpArray[$role . '_company'] = $societe->name;
                                        } else {
                                            $tmpArray[$role . '_company'] = $conf->global->MAIN_INFO_SOCIETE_NOM;
                                        }
                                        break;
                                    case 'socpeople':
                                        $contact = new Contact($this->db);
                                        $societe = new Societe($this->db);
                                        $contact->fetch($objectSignatory->element_id);
                                        $tmpArray[$role . '_job'] = $contact->poste;
                                        if ($contact->fk_soc > 0) {
                                            $societe->fetch($contact->fk_soc);
                                            $tmpArray[$role . '_company'] = $societe->name;
                                        } else {
                                            $tmpArray[$role . '_company'] = $conf->global->MAIN_INFO_SOCIETE_NOM;
                                        }
                                        break;
                                    default:
                                        $tmpArray[$role . '_job']     = '';
                                        $tmpArray[$role . '_company'] = '';
                                        break;
                                }
                                $tmpArray[$role . '_role']           = $outputLangs->transnoentities($objectSignatory->role);
                                $tmpArray[$role . '_signature_date'] = dol_print_date($objectSignatory->signature_date, 'dayhour', 'tzuser');
                                $tmpArray[$role . '_attendance']     = $attendance;
                                if (dol_strlen($objectSignatory->signature) > 0 && $objectSignatory->signature != $langs->transnoentities('FileGenerated')) {
                                    $confSignatureName = dol_strtoupper($this->module) . '_SHOW_SIGNATURE_SPECIMEN';
                                    if ($moreParam['specimen'] == 0 || ($moreParam['specimen'] == 1 && $conf->global->$confSignatureName == 1)) {
                                        $encodedImage = explode(',', $objectSignatory->signature)[1];
                                        $decodedImage = base64_decode($encodedImage);
                                        file_put_contents($tempDir . 'signature' . $objectSignatory->id . '.png', $decodedImage);
                                        $tmpArray[$role . '_signature'] = $tempDir . 'signature' . $objectSignatory->id . '.png';
                                    } else {
                                        $tmpArray[$role . '_signature'] = '';
                                    }
                                } else {
                                    $tmpArray[$role . '_signature'] = '';
                                }
                                $this->setTmpArrayVars($tmpArray, $listLines, $outputLangs);
                                dol_delete_file($tempDir . 'signature' . $objectSignatory->id . '.png');
                            }
                        } else {
                            $tmpArray[$role . '_number']         = '';
                            $tmpArray[$role . '_lastname']       = '';
                            $tmpArray[$role . '_firstname']      = '';
                            $tmpArray[$role . '_job']            = '';
                            $tmpArray[$role . '_company']        = '';
                            $tmpArray[$role . '_role']           = '';
                            $tmpArray[$role . '_signature_date'] = '';
                            $tmpArray[$role . '_attendance']     = '';
                            $tmpArray[$role . '_signature']      = '';
                            $this->setTmpArrayVars($tmpArray, $listLines, $outputLangs);
                        }
                        $odfHandler->mergeSegment($listLines);
                    }
                }
            }
        }
    }

    /**
     * Function to build a document on disk.
     *
     * @param  SaturneDocuments $objectDocument  Object source to build document.
     * @param  Translate        $outputLangs     Lang object to use for output.
     * @param  string           $srcTemplatePath Full path of source filename for generator using a template file.
     * @param  int              $hideDetails     Do not show line details.
     * @param  int              $hideDesc        Do not show desc.
     * @param  int              $hideRef         Do not show ref.
     * @param  array            $moreParam       More param (Object/user/etc).
     * @return int                               1 if OK, <=0 if KO.
     * @throws Exception
     */
    public function write_file(SaturneDocuments $objectDocument, Translate $outputLangs, string $srcTemplatePath, int $hideDetails = 0, int $hideDesc = 0, int $hideRef = 0, array $moreParam): int
    {
        global $conf, $langs;

        $object = $moreParam['object'];

        $signatory = new SaturneSignature($this->db, 'dolimeet', $object->element);

        $tmpArray['declaration_number'] = $conf->global->MAIN_INFO_SOCIETE_TRAINING_ORGANIZATION_NUMBER;

        if (!empty($object->fk_contrat)) {
            require_once DOL_DOCUMENT_ROOT . '/contrat/class/contrat.class.php';
            $contract = new Contrat($this->db);
            $contract->fetch($object->fk_contrat);
            $contract->fetch_optionals();
            if (!empty($contract->array_options['options_label'])) {
                $tmpArray['contract_label'] = $contract->array_options['options_label'];
            } else {
                $tmpArray['contract_label'] = $contract->ref;
            }
            $tmpArray['contract_trainingsession_location'] = $contract->array_options['trainingsession_location'];
        } else {
            $tmpArray['contract_label']                    = '';
            $tmpArray['contract_trainingsession_location'] = '';
        }

        if (!empty($object->fk_project)) {
            require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
            $project = new Project($this->db);
            $project->fetch($object->fk_project);
            $tmpArray['project_ref_label'] = $project->ref . ' - ' . $project->title;
        } else {
            $tmpArray['project_ref_label'] = '';
        }

        $tmpArray['date_start'] = dol_print_date($object->date_start, 'dayhour', 'tzuser');
        $tmpArray['date_end']   = dol_print_date($object->date_end, 'dayhour', 'tzuser');
        $tmpArray['duration']   = convertSecondToTime($object->duration);

        $tmpArray['date_creation'] = dol_print_date(dol_now(), 'dayhour', 'tzuser');

        $moreParam['multipleAttendantsRole'] = 1;
        $moreParam['tmparray']               = $tmpArray;

        return parent::write_file($objectDocument, $outputLangs, $srcTemplatePath, $hideDetails, $hideDesc, $hideRef, $moreParam);
    }
}
