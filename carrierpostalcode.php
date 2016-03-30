<?php
/**
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    Dominique
*  @copyright 2007-2016 Dominique
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class CarrierPostalCode extends CarrierModule
{
    private $html = "";
    private $errors = "";

    public function __construct()
    {
        $this->name = 'carrierpostalcode';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.1';
        $this->author = 'Dominique';
        $this->need_instance = 0;

        $this->bootstrap = true;

        parent::__construct();
        $this->displayName = $this->l('Prix par code postal');
        $this->description = $this->l('Affecte un prix de transport suivant le code postal de livraison');
        $this->confirmUninstall = $this->l('Etes-vous sur de vouloir désinstaller le module ?');
        $this->table_name = $this->name;
    }

    public function install()
    {
        $id_carrier = $this->installCarrier();
        if (!$id_carrier) {
            return false;
        }
        Configuration::updateValue('PS_CARRIERPOSTALCODE_ID_CARRIER', $id_carrier);
        if (!parent::install() or
            !$this->installMyTable() or
            !$this->registerHook('updateCarrier')) {
            return false;
        }
        return true;
    }

    private function installMyTable()
    {
        $sql = '
                CREATE TABLE `'._DB_PREFIX_.$this->table_name.'` (
                    `id_carrierpostalcode` INT(12) NOT NULL AUTO_INCREMENT,
                    `postcode_start` VARCHAR(12) NOT NULL,
                    `postcode_end` VARCHAR(12) NOT NULL,
                    `description` VARCHAR(64),
                    `price` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                    PRIMARY KEY (`id_carrierpostalcode`)
                    ) ENGINE = '._MYSQL_ENGINE_;

        if (!Db::getInstance()->Execute($sql)) {
            return false;
        }
        return true;
    }

    private function installCarrier()
    {
        $carrier = new Carrier();
        $carrier->name = 'ATF 34';
        $carrier->id_tax_rules_group = 0;
        $carrier->active = 1;
        $carrier->deleted = 0;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = 0; // out of range behavior, apply highest
        $carrier->is_module = true;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = true;
        $languages = Language::getLanguages(true);
        foreach ($languages as $language) {
            $carrier->delay[(int)$language['id_lang']] = '24 à 48 Heures';
        }

        if ($carrier->add()) {
            $groups = Group::getGroups(true);
            foreach ($groups as $group) {
                Db::getInstance()->insert('carrier_group', array('id_carrier' => $carrier->id,
                    'id_group' => $group['id_group']));
            }


            // ranges
            $rangeprice = new RangePrice();
            $rangeprice->id_carrier = $carrier->id;
            $rangeprice->delimiter1 = '0';
            $rangeprice->delimiter2 = '10000';
            $rangeprice->add();

            $rangeweight = new RangeWeight();
            $rangeweight->id_carrier = $carrier->id;
            $rangeweight->delimiter1 = '0';
            $rangeweight->delimiter2 = '10000';
            $rangeweight->add();

            $zones = Zone::getZOnes(true);
            foreach ($zones as $zone) {
                Db::getInstance()->insert('carrier_zone', array('id_carrier' => $carrier->id,
                    'id_zone' => $zone['id_zone']));
                Db::getInstance()->insert('delivery', array('id_carrier' => $carrier->id,
                    'id_range_price' => $rangeprice->id, 'id_range_weight' => null,
                    'id_zone' => $zone['id_zone'], 'price' => '0'));
                Db::getInstance()->insert('delivery', array('id_carrier' => $carrier->id,
                    'id_range_weight' => $rangeweight->id, 'id_range_price' => null,
                    'id_zone' => $zone['id_zone'], 'price' => '0'));

            }
            copy(dirname(__FILE__).'\views\img\carrierpostalcode.jpg', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg');
            return $carrier->id;
        }
        return false;
    }

    public function uninstall()
    {
        if (
            !parent::uninstall() or
            !$this->removeCarrier() or
            !$this->removeMyTable() or
            !Configuration::deleteByName('CARRIER_POSTAL_CODE')
            ) {
            return false;
        }

        return true;
    }

    private function removeMyTable()
    {
        if (!Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.$this->table_name.'`')) {
            return false;
        }
        return true;
    }

    private function removeCarrier()
    {
        if (
            !Db::getInstance()->update(
                'carrier',
                array('deleted' => 1),
                '`external_module_name` = "carrierpostalcode"'
            )) {
            return false;
        }
        return true;
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        $shipping = "";
        $shipping .= $shipping_cost;

        $price = false;
        $postcodes = $this->getAll();
        $postCodeCustomer = new Address((int)$params->id_address_delivery);

        if (empty($content)) {
            foreach ($postcodes as $postcode) {
                if (
                    $postCodeCustomer->postcode >= $postcode['postcode_start'] &&
                    $postCodeCustomer->postcode <= $postcode['postcode_end']
                    ) {
                        if(!$price || $price >= $postcode['price']) {
                            $price = $postcode['price'];
                        }
                }
            }
        }
        if (!$price) {
            return false;
        }
        return (float)$price;
    }

    public function getOrderShippingCostExternal($params)
    {
        new Address((int)$params->id_address_delivery);
        return false;
    }

    public function hookUpdateCarrier($params)
    {
        if ((int)$params['id_carrier'] != (int)$params['carrier']) {
            if ((int)$params['id_carrier'] == Configuration::get('PS_CARRIERPOSTALCODE_ID_CARRIER')) {
                Configuration::updateValue('PS_CARRIERPOSTALCODE_ID_CARRIER', (int)$params['carrier']->id);
            }
        }
    }

    public function getContent()
    {
        $this->postProcess();
        $this->displayForm();
        return $this->html;
    }

    private function postProcess()
    {
        $carrierpostalcode = [];
        if (Tools::isSubmit('submitNewCarrierPostalCode')) {
            $carrierpostalcode['postcode_start'] = Tools::getValue('postcode_start');
            $carrierpostalcode['postcode_end'] = Tools::getValue('postcode_end');
            $carrierpostalcode['price'] = Tools::getValue('price');
            $carrierpostalcode['description'] = Tools::getValue('description');

            if (
                empty($carrierpostalcode['postcode_start']) ||
                empty($carrierpostalcode['postcode_end']) ||
                empty($carrierpostalcode['description'])
                ) {
                $this->errors[] = Tools::displayError($this->l('Un ou plusieurs champs sont vide.'));
            }

            foreach ($carrierpostalcode as $key => $value) {
                if (!Validate::isGenericName($value)) {
                    $this->errors[] = Tools::displayError($this->l('Champs Invalide')) . " : " . $key;
                }
            }
            if (!Validate::isPostCode($carrierpostalcode['postcode_start']) ||
                !Validate::isPostCode($carrierpostalcode['postcode_end'])) {
                $this->errors[] = Tools::displayError($this->l('Code postal de début ou de fin invalide !'));
            }
            if ($carrierpostalcode['postcode_start'] > $carrierpostalcode['postcode_end']) {
                $this->errors[] = Tools::displayError(
                    $this->l('Le code postal de fin doit être plus grand que le code postal de début !')
                );
            }
            if (!Validate::isPrice($carrierpostalcode['price'])) {
                $this->errors[] = Tools::displayError($this->l('Le format du prix est invalide !'));
            }

            if (!$this->errors) {
                if (Tools::getValue('id_carrierpostalcode')) {
                    if (!Db::getInstance()->update(
                        $this->table_name,
                        $carrierpostalcode,
                        'id_carrierpostalcode = ' . (int)Tools::getValue('id_carrierpostalcode')
                    )) {
                        $this->errors[] = Tools::displayError(
                            $this->l('Erreur lors de la mise à jour de la base de donnée')
                        ). ': '. mysqli_error();
                    }
                } else {
                    if (!Db::getInstance()->insert($this->table_name, $carrierpostalcode)) {
                        $this->errors[] = Tools::displayError(
                            $this->l('Erreur lors de l\'insertion en base de donnée')
                        ). ': '. mysqli_error();
                    }
                }

                $confirmation = Tools::getValue('id_carrierpostalcode')
                    ? $this->l('Mise à jour éffectuée.')
                    : $this->l('Nouvelle entrée créée.');
            }

            if ($this->errors) {
                $this->html .= $this->displayError(implode($this->errors, '<br />'));
            } else {
                $this->html .= $this->displayConfirmation($confirmation);
            }
        } elseif (Tools::isSubmit('deletecarrierpostalcode')) {
            $id_carrierpostalcode = Tools::getValue('id_carrierpostalcode');
            if (!Db::getInstance()->delete($this->table_name, 'id_carrierpostalcode = ' . (int)$id_carrierpostalcode)) {
                $this->errors[] = Tools::displayError(
                    $this->l('Erreur lors de la suppression du code postal')
                )
                . ' : '. mysqli_error();
            }
            $confirmation = $this->l('Code postal éffaçé.');

            if ($this->errors) {
                $this->html .= $this->displayError(implode($this->errors, '<br />'));
            } else {
                $this->html .= $this->displayConfirmation($confirmation);
            }
        }
    }

    private function displayForm()
    {
        if (Tools::isSubmit('updatecarrierpostalcode')) {
            $this->html .= $this->generateForm(true);
        } else {
            $this->html .= $this->generateCarrierPostalList();
            $this->html .= $this->generateForm();
        }

    }

    private function generateForm($editing = false)
    {
        $inputs = array();

        if ($editing) {
            $inputs[] = array(
                'type' => 'hidden',
                'name' => 'id_carrierpostalcode'
            );
        }

        $inputs[] = array(
                    'type' => 'text',
                    'label' => $this->l('Code Postal Début.'),
                    'name' => 'postcode_start',
                    'desc' => $this->l('Entrez un code postal de début.'),
                    'maxcar' => '12',
                    'class' => 'input fixed-width-sm',
                    'required' => true
            );

        $inputs[] = array(
                    'type' => 'text',
                    'label' => $this->l('Code Postal Fin.'),
                    'name' => 'postcode_end',
                    'desc' => $this->l('Entrez un code postal de fin.'),
                    'maxcar' => '12',
                    'class' => 'input fixed-width-sm',
                    'required' => true
            );

        $inputs[] = array(
                    'type' => 'text',
                    'label' => $this->l('Prix.'),
                    'name' => 'price',
                    'desc' => $this->l('Entrez un prix.'),
                    'maxcar' => '22',
                    'class' => 'input fixed-width-sm',
                    'required' => true
            );

        $inputs[] = array(
                    'type' => 'text',
                    'label' => $this->l('Description.'),
                    'name' => 'description',
                    'desc' => $this->l('Entrez une description.'),
                    'placeholder' => $this->l('Entrez une description.'),
            'required' => true,
                    'maxcar' => '64'
            );

        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $editing ? $this->l('Modification') : $this->l('Ajouter'),
                    'icon' => 'icon-cogs'
                    ),
                'input' => $inputs,
                'submit' => array(
                    'title' => $editing ? $this->l('Mettre à jour') : $this->l('Ajouter'),
                    'class' => 'btn btn-default pull-right'
                    )
                )
            );
        $values = array();
        if (!$editing) {
            foreach ($inputs as $input) {
                $values[$input['name']] = '';
            }
        } else {
            $values = $this->getSingle(Tools::getValue('id_carrierpostalcode'));
        }


        $helper = new HelperForm();
        $helper->submit_action = 'submitNewCarrierPostalCode';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.
        '&tab_module='.$this->tab.'&module_name'.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
                'fields_value' => $values
            );
        return $helper->generateForm(array($fields_form));
    }

    private function generateCarrierPostalList()
    {
        $content = $this->getAll();
        $fields_list = array(
            'id_carrierpostalcode' => array(
                'title' => 'ID',
                'align' => 'center',
                'class' => 'fixed-width-xs'
                ),
            'postcode_start' => array(
                'title' => $this->l('CP début'),
                'class' => 'fixed-width-sm'
                ),
            'postcode_end' => array(
                'title' => $this->l('CP fin'),
                'class' => 'fixed-width-sm'
                ),
            'price' => array(
                'title' => $this->l('prix'),
                'class' => 'fixed-width-sm'
                ),
            'description' => array(
                'title' => $this->l('Description')
                ),
            );
        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->actions = array('edit', 'delete');
        $helper->module = $this;
        $helper->listTotal = count($content);
        $helper->identifier = 'id_carrierpostalcode';
        $helper->title = $this->l('Liste des codes postaux');
        $helper->table = $this->table_name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->context->link->getAdminLink(
            'AdminModules',
            false
        )
        . '&configure=' . $this->name . '&module_name=' .$this->name;

        return $helper->generateList($content, $fields_list);
    }

    private function getsingle($id_carrierpostalcode)
    {
        return Db::getInstance()->getRow('
            SELECT *
            FROM '._DB_PREFIX_.$this->table_name.'
            WHERE id_carrierpostalcode = '.(int)$id_carrierpostalcode);
    }

    private function getAll()
    {
        return Db::getInstance()->ExecuteS(
            'SELECT *
            FROM '._DB_PREFIX_.$this->table_name
        );
    }

    public function getConfigFieldsValues()
    {
        return array(
                'code_postal' => Configuration::get('CARRIER_POSTAL_CODE')
            );
    }
}
