<?php
/* Copyright (C) 2020      Open-DSI             <support@open-dsi.fr>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/ecommerceng/class/business/eCommercePendingWebHook.class.php
 * \ingroup ecommerceng
 * \brief
 */


/**
 * Class eCommercePendingWebHook
 *
 * Put here description of your class
 */
class eCommercePendingWebHook
{
    /**
     * @var DoliDB Database handler.
     */
    public $db;
    /**
     * @var string Error
     */
    public $error = '';
    /**
     * @var array Errors
     */
    public $errors = array();
	/**
	 * @var array Warnings
	 */
	public $warnings = array();

	/**
	 * @var int		ID
	 */
	public $id;

	/**
	 * @var int		Site ID
	 */
	public $site_id;
	/**
	 * @var string	WebHook ID
	 */
	public $delivery_id;
	/**
	 * @var string	WebHook ID
	 */
	public $webhook_id;
	/**
	 * @var string	WebHook topic
	 */
	public $webhook_topic;
	/**
	 * @var string	WebHook resource
	 */
	public $webhook_resource;
	/**
	 * @var string	WebHook event
	 */
	public $webhook_event;
	/**
	 * @var string	WebHook data
	 */
	public $webhook_data;
	/**
	 * @var string	WebHook signature
	 */
	public $webhook_signature;
	/**
	 * @var string	WebHook source
	 */
	public $webhook_source;

	/**
	 * @var int		Date creation
	 */
	public $datec;
	/**
	 * @var int		Date processed
	 */
	public $datep;
	/**
	 * @var int		Date error
	 */
	public $datee;
	/**
	 * @var int		Error message when processing
	 */
	public $error_msg;

	/**
	 * @var eCommerceSite[]		eCommerceSite handler cached
	 */
	public static $site_cached;
	/**
	 * @var eCommerceSynchro[]		eCommerceSynchro handler cached
	 */
	public static $synchro_cached;

	const STATUS_NOT_PROCESSED = 0;
	const STATUS_PROCESSED = 1;
	const STATUS_ERROR = 2;
	const STATUS_WARNING = 3;

	/**
     * Constructor
     *
     * @param        DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

	/**
	 * Check if the data is OK
	 *
	 * @return	int 			-1 if bad values, -2 if unauthorized, 1 if OK
	 */
	public function check()
	{
		if (!($this->site_id > 0) || empty($this->delivery_id) || empty($this->webhook_id) || empty($this->webhook_topic) || empty($this->webhook_resource) ||
			empty($this->webhook_event) || empty($this->webhook_data) || empty($this->webhook_signature) || empty($this->webhook_source)
		) {
			// Bad values
			return -1;
		}

		$site = $this->getSite($this->site_id);
		if (!is_object($site)) {
			// Bad values
			return -1;
		}

		if (empty($site->parameters['web_hooks_secret']) || $this->webhook_signature != base64_encode(hash_hmac("sha256", $this->webhook_data, $site->parameters['web_hooks_secret'], true))) {
			// Unauthorized
			return -2;
		}

		return 1;
	}

	/**
	 * Create webhook
	 *
	 * @return	int 			<0 if KO, >0 if OK
	 */
	public function create()
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$error = 0;
		$now = dol_now();

		// Insert webhook
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "ecommerce_pending_webhooks (";
		$sql .= "  site_id";
		$sql .= ", delivery_id";
		$sql .= ", webhook_id";
		$sql .= ", webhook_topic";
		$sql .= ", webhook_resource";
		$sql .= ", webhook_event";
		$sql .= ", webhook_data";
		$sql .= ", webhook_signature";
		$sql .= ", webhook_source";
		$sql .= ", status";
		$sql .= ", datec";
		$sql .= ")";
		$sql .= " VALUES (";
		$sql .= "   " . $this->site_id;
		$sql .= ", '" . $this->db->escape($this->delivery_id) . "'";
		$sql .= ", '" . $this->db->escape($this->webhook_id) . "'";
		$sql .= ", '" . $this->db->escape($this->webhook_topic) . "'";
		$sql .= ", '" . $this->db->escape($this->webhook_resource) . "'";
		$sql .= ", '" . $this->db->escape($this->webhook_event) . "'";
		$sql .= ", '" . $this->db->escape($this->webhook_data) . "'";
		$sql .= ", '" . $this->db->escape($this->webhook_signature) . "'";
		$sql .= ", '" . $this->db->escape($this->webhook_source) . "'";
		$sql .= ", " . self::STATUS_NOT_PROCESSED;
		$sql .= ", '" . $this->db->idate($now) . "'";
		$sql .= ")";

		$this->db->begin();

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = 'Error ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . " SQL: " . $sql . "; Error: " . $this->db->lasterror(), LOG_ERR);
			$error++;
		}

		if (!$error) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX . 'ecommerce_pending_webhooks');
		}

		// Commit or rollback
		if ($error) {
			$this->db->rollback();
			return -1 * $error;
		} else {
			$this->db->commit();
			return $this->id;
		}
	}

	/**
	 * Delete webhook
	 *
	 * @param 	int		$row_id		WebHook line ID
	 * @return	int 				<0 if KO, >0 if OK
	 */
	public function delete($row_id  = 0)
	{
		dol_syslog(__METHOD__ . " row_id=$row_id", LOG_DEBUG);

		if (!($row_id > 0)) $row_id = $this->id;
		$error = 0;

		// Delete webhook
		$sql = 'DELETE FROM ' . MAIN_DB_PREFIX . 'ecommerce_pending_webhooks WHERE rowid = ' . $row_id;

		$this->db->begin();

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = 'Error ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . " SQL: " . $sql . "; Error: " . $this->db->lasterror(), LOG_ERR);
			$error++;
		}

		// Commit or rollback
		if ($error) {
			$this->db->rollback();
			return -1 * $error;
		} else {
			$this->db->commit();
			return 1;
		}
	}

	/**
	 * Set status of the webhook to processed
	 *
	 * @param 	int		$row_id		WebHook line ID
	 * @return	int 				<0 if KO, >0 if OK
	 */
	public function setStatusProcessed($row_id)
	{
		dol_syslog(__METHOD__ . " row_id=$row_id", LOG_DEBUG);

		if (!($row_id > 0)) $row_id = $this->id;
		$now = dol_now();
		$error = 0;

		// Set status processed
		$sql = "UPDATE " . MAIN_DB_PREFIX . "ecommerce_pending_webhooks SET" .
			"  status = " . self::STATUS_PROCESSED .
			", datep = '" . $this->db->idate($now) . "'" .
			", datee = NULL" .
			", error_msg = NULL" .
			" WHERE rowid = " . $row_id .
			" AND status IN (" . self::STATUS_NOT_PROCESSED . "," . self::STATUS_ERROR . ")";

		$this->db->begin();

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = 'Error ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . " SQL: " . $sql . "; Error: " . $this->db->lasterror(), LOG_ERR);
			$error++;
		}

		// Commit or rollback
		if ($error) {
			$this->db->rollback();
			return -1 * $error;
		} else {
			$this->db->commit();
			return 1;
		}
	}

	/**
	 * Set status of the webhook to error
	 *
	 * @param 	int		$row_id		WebHook line ID
	 * @param 	string	$error_msg	Error message
	 * @return	int 				<0 if KO, >0 if OK
	 */
	public function setStatusError($row_id, $error_msg)
	{
		dol_syslog(__METHOD__ . " row_id=$row_id", LOG_DEBUG);

		if (!($row_id > 0)) $row_id = $this->id;
		$now = dol_now();
		$error = 0;

		// Set status error
		$sql = "UPDATE " . MAIN_DB_PREFIX . "ecommerce_pending_webhooks SET" .
			"  status = " . self::STATUS_ERROR .
			", datee = '" . $this->db->idate($now) . "'" .
			", error_msg = '" . $this->db->escape($error_msg) . "'" .
			" WHERE rowid = " . $row_id .
			" AND status IN (" . self::STATUS_NOT_PROCESSED . "," . self::STATUS_ERROR . ")";

		$this->db->begin();

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = 'Error ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . " SQL: " . $sql . "; Error: " . $this->db->lasterror(), LOG_ERR);
			$error++;
		}

		// Commit or rollback
		if ($error) {
			$this->db->rollback();
			return -1 * $error;
		} else {
			$this->db->commit();
			return 1;
		}
	}

	/**
	 * Set status of the webhook to warning
	 *
	 * @param 	int		$row_id			WebHook line ID
	 * @param 	string	$warning_msg	Warning message
	 * @return	int 					<0 if KO, >0 if OK
	 */
	public function setStatusWarning($row_id, $warning_msg)
	{
		dol_syslog(__METHOD__ . " row_id=$row_id", LOG_DEBUG);

		if (!($row_id > 0)) $row_id = $this->id;
		$now = dol_now();
		$error = 0;

		// Set status error
		$sql = "UPDATE " . MAIN_DB_PREFIX . "ecommerce_pending_webhooks SET" .
			"  status = " . self::STATUS_WARNING .
			", datee = '" . $this->db->idate($now) . "'" .
			", error_msg = '" . $this->db->escape($warning_msg) . "'" .
			" WHERE rowid = " . $row_id .
			" AND status IN (" . self::STATUS_NOT_PROCESSED . "," . self::STATUS_WARNING . ")";

		$this->db->begin();

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = 'Error ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . " SQL: " . $sql . "; Error: " . $this->db->lasterror(), LOG_ERR);
			$error++;
		}

		// Commit or rollback
		if ($error) {
			$this->db->rollback();
			return -1 * $error;
		} else {
			$this->db->commit();
			return 1;
		}
	}

	/**
	 * Synchronize webhook
	 *
	 * @param 	int		$row_id		WebHook line ID
	 * @return	int 				<0 if KO, >0 if OK
	 */
	public function synchronize($site_id = 0, $webhook_topic = '', $webhook_resource = '', $webhook_event = '', $data = array())
	{
		global $langs;
		dol_syslog(__METHOD__ . " site_id=$site_id, webhook_topic=$webhook_topic, webhook_resource=$webhook_resource, webhook_event=$webhook_event, data=" . json_encode($data), LOG_DEBUG);

		if (empty($site_id) && $this->site_id > 0) $site_id = $this->site_id;
		if (empty($webhook_topic) && !empty($this->webhook_topic)) $webhook_topic = $this->webhook_topic;
		if (empty($webhook_resource) && !empty($this->webhook_resource)) $webhook_resource = $this->webhook_resource;
		if (empty($webhook_event) && !empty($this->webhook_event)) $webhook_event = $this->webhook_event;
		if (empty($data) && !empty($this->webhook_data)) $data = $this->webhook_data;

		$this->error = '';
		$this->errors = array();

		$synchro = $this->getSynchro($site_id);
		if (!is_object($synchro)) {
			return -1;
		}

		if (empty($webhook_resource) || empty($webhook_event)) {
			$tmp = explode('.', $webhook_topic);
			$webhook_resource = $tmp[0];
			$webhook_event = $tmp[1];
		}

		$result = 1;

		// Order
		if ($webhook_resource == 'order') {
			if ($webhook_event == 'created' || $webhook_event == 'updated') {
				if ($webhook_event == 'updated') {
					$result = $synchro->isOrderExistFromData($data);
					if ($result == 0) {
						$langs->load('errors');
						$this->warnings[] = $langs->trans('ErrorRecordNotFound');
						return -2;
					}
				}
				if ($result > 0) $result = $synchro->synchronizeOrderFromData($data);
			}
		}

		if ($result < 0) {
			$this->error = $synchro->error;
			$this->errors = $synchro->errors;
			return -1;
		}

		return 1;
	}

	/**
	 *  Process all pending webhooks (cron)
	 *
	 *  @return	int				0 if OK, < 0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function cronProcessPendingWebHooks()
	{
		global $const, $user, $langs;

		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		$langs->load('ecommerceng@ecommerceng');
		$output = '';

		try {
			if (!$user->rights->ecommerceng->write) {
				$langs->load('errors');
				$this->error = $langs->trans('ErrorForbidden');
				$this->errors = array();
				dol_syslog(__METHOD__ . " Error: " . $this->error, LOG_ERR);
				return -1;
			}

			$error = 0;

			if (empty($const->global->ECOMMERCE_PROCESSING_WEBHOOK_SYNCHRONIZATION)) {
				dolibarr_set_const($this->db, 'ECOMMERCE_PROCESSING_WEBHOOK_SYNCHRONIZATION', dol_print_date(dol_now(), 'dayhour'), 'chaine', 1, 'Token the processing of the synchronization of the site by webhooks', 0);

				// Archive successful pending lines before x days into a file
				$result = $this->archive();
				if ($result < 0) {
					dolibarr_del_const($this->db, 'ECOMMERCE_PROCESSING_WEBHOOK_SYNCHRONIZEATION', 0);
					return -1;
				}

				$sql = "SELECT rowid, site_id, webhook_topic, webhook_resource, webhook_event, webhook_data";
				$sql .= " FROM " . MAIN_DB_PREFIX . "ecommerce_pending_webhooks";
				$sql .= " WHERE status IN (" . self::STATUS_NOT_PROCESSED . ")";
				$sql .= " ORDER BY rowid ASC";

				$resql = $this->db->query($sql);
				if (!$resql) {
					dolibarr_del_const($this->db, 'ECOMMERCE_PROCESSING_WEBHOOK_SYNCHRONIZEATION', 0);
					$this->error = 'Error ' . $this->db->lasterror();
					$this->errors = array();
					dol_syslog(__METHOD__ . " SQL: " . $sql . "; Error: " . $this->db->lasterror(), LOG_ERR);
					return -1;
				}

				while ($obj = $this->db->fetch_object($resql)) {
					$result = $this->synchronize($obj->site_id, $obj->webhook_topic, $obj->webhook_resource, $obj->webhook_event, json_decode($obj->webhook_data));
					if ($result > 0) $result = $this->setStatusProcessed($obj->rowid);
					elseif ($result == -2) $this->setStatusWarning($obj->rowid, $this->warningsToString());
					else $this->setStatusError($obj->rowid, $this->errorsToString());
					if ($result == -2) {
						$output .= $langs->trans('ECommerceWarningSynchronizeWebHook', $obj->rowid, $obj->webhook_topic) . ":<br>";
						$output .= '<span style="color: orangered;">' . $langs->trans('Warning') . ': ' . $this->warningsToString() . '</span>' . "<br>";
						$error++;
					} elseif ($result < 0) {
						$output .= $langs->trans('ECommerceErrorSynchronizeWebHook', $obj->rowid, $obj->webhook_topic) . ":<br>";
						$output .= '<span style="color: red;">' . $langs->trans('Error') . ': ' . $this->errorsToString() . '</span>' . "<br>";
						$error++;
					}
				}
				$this->db->free($resql);

				dolibarr_del_const($this->db, 'ECOMMERCE_PROCESSING_WEBHOOK_SYNCHRONIZATION', 0);

				if ($error) {
					$this->error = $output;
					$this->errors = array();
					return -1;
				} else {
					$output .= $langs->trans('ECommerceSynchronizeWebHooksSuccess');
				}
			} else {
				$output .= $langs->trans('ECommerceAlreadyProcessingWebHooksSynchronization') . ' (' . $langs->trans('ECommerceSince') . ' : ' . $const->global->ECOMMERCE_PROCESSING_WEBHOOK_SYNCHRONIZATION . ')';
			}

			$this->error = "";
			$this->errors = array();
			$this->output = $output;
			$this->result = array("commandbackuplastdone" => "", "commandbackuptorun" => "");

			return 0;
		} catch (Exception $e) {
			dolibarr_del_const($this->db, 'ECOMMERCE_PROCESSING_WEBHOOK_SYNCHRONIZATION', 0);
			$output .= $langs->trans('ECommerceErrorWhenProcessPendingWebHooks') . ":<br>";
			$output .= '<span style="color: red;">' . $langs->trans('Error') . ': ' . $e->getMessage() . '</span>' . "<br>";
			$this->error = $output;
			$this->errors = array();
			return -1;
		}
	}

	/**
	 * Get eCommerceSite handler by site ID
	 *
	 * @param	int						$site_id		Site ID
	 * @return	eCommerceSite|int						<0 if KO, 0 if not found otherwise Site handler
	 */
	public function getSite($site_id)
	{
		global $langs;

		if (!isset(self::$site_cached[$site_id])) {
			dol_include_once("/ecommerceng/class/data/eCommerceSite.class.php");
			$site = new eCommerceSite($this->db);
			$result = $site->fetch($site_id);
			if ($result < 0) {
				$this->error = $site->error;
				$this->errors = $site->errors;
				dol_syslog(__METHOD__ . ' Site ID: ' . $site_id. '; Errors: ' . $this->errorsToString(), LOG_ERR);
				return -1;
			} elseif ($result == 0) {
				$langs->load('errors');
				$this->errors[] = $langs->trans('ErrorRecordNotFound') . '; Site ID: ' . $site_id;
				dol_syslog(__METHOD__ . ' ' . $this->errorsToString(), LOG_ERR);
				return -1;
			}

			self::$site_cached[$site_id] = $site;
		}

		return self::$site_cached[$site_id];
	}

	/**
	 * Get eCommerceSynchro handler by site ID
	 *
	 * @param	int						$site_id		Site ID
	 * @return	eCommerceSynchro|int					<0 if KO, 0 if not found otherwise Synchro handler
	 */
	public function getSynchro($site_id)
	{
		if (!isset(self::$synchro_cached[$site_id])) {
			$site = $this->getSite($site_id);
			if (!is_object($site)) {
				return -1;
			}

			dol_include_once('/ecommerceng/class/business/eCommerceSynchro.class.php');
			$synchro = new eCommerceSynchro($this->db, $site);

			$result = $synchro->connect();
			if ($result < 0) {
				$this->error = $synchro->error;
				$this->errors = $synchro->errors;
				return -1;
			}

			self::$synchro_cached[$site_id] = $synchro;
		}

		return self::$synchro_cached[$site_id];
	}

	/**
	 * Archive last success synchronization into a log file
	 *
	 * @return	int					<0 if KO, >0 if OK
	 */
	public function archive()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
		$error = 0;
		$period = !empty($conf->global->ECOMMERCE_PROCESSING_WEBHOOK_LOGS_BEFORE_X_DAYS) ? abs($conf->global->ECOMMERCE_PROCESSING_WEBHOOK_LOGS_BEFORE_X_DAYS) : 7;
		$log_before_date = dol_time_plus_duree(dol_now(), -$period, 'd');

		$sql = "SELECT rowid, datec, site_id, delivery_id, webhook_id, webhook_topic, webhook_resource" .
			", webhook_event, webhook_data, webhook_signature, webhook_source, status, datep, datee, error_msg" .
			" FROM " . MAIN_DB_PREFIX . "ecommerce_pending_webhooks" .
			" WHERE status IN (" . self::STATUS_PROCESSED . ")" .
			" AND datec < '" . $this->db->idate($log_before_date) . "'" .
			" ORDER BY rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$error++;
			$this->error = 'Error ' . $this->db->lasterror();
			$this->errors = array();
			dol_syslog(__METHOD__ . " SQL: " . $sql . "; Error: " . $this->db->lasterror(), LOG_ERR);
		} elseif ($this->db->num_rows($resql) > 0) {
			if (empty($conf->global->SYSLOG_FILE)) $logfile = DOL_DATA_ROOT . '/woosync_webhooks.log';
			else $logfile = dirname(str_replace('DOL_DATA_ROOT', DOL_DATA_ROOT, $conf->global->SYSLOG_FILE)) . "/woosync_webhooks.log";

			// Open/create log file
			$filefd = @fopen($logfile, 'a+');
			if (!$filefd) {
				$error++;
				$this->error = 'Failed to open woosync webhooks log file ' . $logfile;
				$this->errors = array();
				dol_syslog(__METHOD__ . " Error: " . $this->error, LOG_ERR);
			} else {
				while ($obj = $this->db->fetch_object($resql)) {
					$data = array(
						0 => dol_print_date($obj->datec, 'standard'),
						1 => dol_print_date($obj->datep, 'standard'),
						2 => $obj->site_id,
						3 => $obj->delivery_id,
						4 => $obj->webhook_id,
						5 => $obj->webhook_topic,
						6 => $obj->webhook_resource,
						7 => $obj->webhook_event,
						8 => $obj->webhook_source,
						9 => $obj->webhook_data,
					);

					$result = @fputcsv($filefd, $data);
					if ($result === false) {
						$error++;
						$this->error = 'Failed to write into woosync webhooks log file ' . $logfile;
						$this->errors = array();
						dol_syslog(__METHOD__ . " Error: " . $this->error, LOG_ERR);
						break;
					}

					$sql2 = "DELETE FROM " . MAIN_DB_PREFIX . "ecommerce_pending_webhooks WHERE rowid = " . $obj->rowid;
					$resql2 = $this->db->query($sql2);
					if (!$resql2) {
						$error++;
						$this->error = 'Error ' . $this->db->lasterror();
						$this->errors = array();
						dol_syslog(__METHOD__ . " SQL: " . $sql2 . "; Error: " . $this->db->lasterror(), LOG_ERR);
						break;
					}
				}
			}
			fclose($filefd);
			@chmod($logfile, octdec(empty($conf->global->MAIN_UMASK) ? '0664' : $conf->global->MAIN_UMASK));

			$this->db->free($resql);
		}

		return $error ? -1 : 1;
	}

	/**
	 * Method to output saved errors
	 *
	 * @param   string      $separator      Separator between each error
	 * @return	string		                String with errors
	 */
	public function errorsToString($separator = ', ')
	{
		return $this->error . (is_array($this->errors) ? (!empty($this->error) ? $separator : '') . join($separator, $this->errors) : '');
	}

	/**
	 * Method to output saved warnings
	 *
	 * @param   string      $separator      Separator between each error
	 * @return	string		                String with warnings
	 */
	public function warningsToString($separator = ', ')
	{
		return join($separator, $this->warnings);
	}
}