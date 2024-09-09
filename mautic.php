<?php
/**
 * @package            Joomla
 * @subpackage         Event Booking
 * @author             Lorenzo Giovannini
 * @copyright          Copyright (C) 2010 - 2020 Istituto Medicina Naturale
 * @license            GNU/GPL, see LICENSE.php
 * @version             2.0
 * @note                aggiornato il campo professione
 */

// no direct access
defined('_JEXEC') or die;

use Joomla\Utilities\ArrayHelper;
use Joomla\Registry\Registry;
use Joomla\CMS\Http\HttpFactory;
use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;
use Lorenzogiovannini\Mautic\Log;
use Joomla\CMS\Uri\Uri as CMSUri;

class plgEventbookingMautic extends JPlugin
{
	/**
	 * Application object.
	 *
	 * @var    JApplicationCms
	 */
	protected $app;

	/**
	 * Database object.
	 *
	 * @var    JDatabaseDriver
	 */
	protected $db;

	/**
	 * Constructor.
	 *
	 * @param object   $subject
	 * @param Registry $config
	 */
    
	public function __construct(& $subject, $config)
	{
		parent::__construct($subject, $config);

		JFactory::getLanguage()->load('plg_eventbooking_joomlagroups', JPATH_ADMINISTRATOR);

        $ciccio = 'lollo';

	}

    private function AuthConfig() {
        $params = new Registry($this->params);
        $this->username = $params->get('mauticUserName');
        $this->password = $params->get('mauticPassword');
        $this->baseUrl = $params->get('mauticBaseUrl');
        $this->property = $params->get('proprieta');

        $composerAutoload = __DIR__ . '/vendor/autoload.php';

        if (file_exists($composerAutoload))
        {
            $loader = require_once $composerAutoload;
        }

        // ApiAuth->newAuth() will accept an array of Auth settings
        $settings = [
            'userName'   => $this->username, 
            'password'   => $this->password, 
        ];

        $apiUrl   = $this->baseUrl;

        $http = HttpFactory::getHttp();

        // Initiate the auth object specifying to use BasicAuth
        $initAuth = new ApiAuth($http);
        $auth     = $initAuth->newAuth($settings, 'BasicAuth');

        return $auth;
    }

	/**
	 * Render settings form
	 *
	 * @param $row
	 *
	 * @return array
	 */
	public function onEditEvent($row)
	{
		if (!$this->canRun($row))
        {
            return;
        }

        ob_start();
		$this->drawSettingForm($row);

		return array('title' => '<i class="fas fa-mail-bulk"></i> Mautic',
		             'form'  => ob_get_clean(),
        );
	}

	/**
	 * Store setting into database
	 *
	 * @param EventbookingTableEvent $row
	 * @param Boolean                $isNew true if create new plan, false if edit
	 */
	public function onAfterSaveEvent($row, $data, $isNew)
	{
		if (!$this->canRun($row))
		{
			return;
		}
        //carico istanza
        $params = new Registry($row->params);
        // salvo attivazione plugin
        $params->set('attivazioneMautic', $data['attivazioneMautic']);
        // salvo id segmento
        $params->set('segmentoMautic', $data['segmentoMautic']);
        // salvo tags
        if (isset($data['tagsMautic']))
        {
            $mautic_tags = implode(',', $data['tagsMautic']);
        }
        else
        {
            $mautic_tags = '';
        }
        
        $params->set('tagsMautic', $mautic_tags);

        // salvo id campagna
        $params->set('campainsMautic', $data['campainsMautic']);

        $row->params = $params->toString();
        $row->store();
	}

	/**
	 * This method is run after registration record is stored into database
	 *
	 * @param EventbookingTableRegistrant $row
	 */
	public function onAfterStoreRegistrant($row)
	{
		if ($row->user_id
			&& $this->params->get('assign_offline_pending_registrants', '0')
			&& strpos($row->payment_method, 'os_offline') !== false)
		{
			$this->assignToUserGroups($row);
		}
    }
    

	/**
	 * Add registrants to mautic, assign segment and tags. to develop the check if user already exist
	 *
	 * @param EventbookingTableRegistrant $row
	 */
	public function onAfterPaymentSuccess($row)
	{
        $event = EventbookingHelperDatabase::getEvent($row->event_id);
        $eventParams = new Registry($event->params);
        
        if ($eventParams->get('attivazioneMautic') == 'on') {
        
            $logFile = JPATH_SITE.'/logs/eb-mautic-plg.txt';

            $segmentoMauticId = $eventParams->get('segmentoMautic');
            $tagsMauticIds = $eventParams->get('tagsMautic');
            $campagnaMauticId = $eventParams->get('campainsMautic');;

            $auth = self::authConfig();

            $campiUtente  = EventbookingHelperRegistration::getRegistrantData($row);

            $mauticUser = array(
                'ipAddress' => $row->user_ip,
                'firstname' => $row->first_name,
                'lastname'	=> $row->last_name,
                'email'		=> $row->email,
                'mobile'    => $campiUtente["phone"],
                'professione'  => $campiUtente["Professione"],
                'address1'  => $campiUtente["Indirizzo"],
                'city'      => $campiUtente["Citta"],
                'zipcode'   => $campiUtente["CAP"],
                'company'   => $this->property,
                'tags'      => $tagsMauticIds,
                'overwriteWithBlank' => false
            );

            //cerco utente in mautic
            $api        = new MauticApi();
            $contactApi = $api->newApi("contacts", $auth, $this->baseUrl);
            $searchFilter = 'email:'.$row->email;

            $contacts = $contactApi->getList($searchFilter, $start, $limit, $orderBy, $orderByDir, $publishedOnly, true);

            //se non esiste lo creo
            if ($contacts["total"] == 0) {
                // creazione utente
                $contact = $contactApi->create($mauticUser);
                $mauticUserId = $contact["contact"]["id"];
            } else {
                //se esiste lo aggiorno
                $propertiesNames = array_keys($contacts["contacts"]);
                $mauticUserId = $propertiesNames[0];
                if (!is_null($mauticUserId)) {
                    // 
                    $createIfNotFound = true;
                    $contact = $contactApi->edit($mauticUserId, $mauticUser, $createIfNotFound);
                    $mauticUserId = $contact["contact"]["id"];
                    //log
                }
            }
            
            if (!is_null($mauticUserId) && !is_null($segmentoMauticId)) {
                // assegna utente a segmento
                $segmentApi = $api->newApi("segments", $auth, $this->baseUrl);
                $response = $segmentApi->addContact($segmentoMauticId, $mauticUserId);
                
                // handle error
                if (!isset($response['success'])) {
                    $logMsg = 'ERR assegna utente a segmento: code'.$response["errors"][0]["code"].' - '.$response["errors"][0]["message"];
                    Log::logData($logFile, $mauticUser, $logMsg);
                } else {
                    $logMsg = ' assegna utente a segmento: '.$segmentoMauticId;
                    Log::logData($logFile, $mauticUser, $logMsg);
                }
            }

            if (!is_null($mauticUserId) && !empty($campagnaMauticId)) {
                // assegna utente a campagna/e
                $campaignApi = $api->newApi("campaigns", $auth, $this->baseUrl);
                foreach ($campagnaMauticId as $id) {
                    $response = $campaignApi->addContact($id, $mauticUserId);
                    if (!isset($response['success'])) {
                        $logMsg = 'ERR assegna utente a campagna: code'.$response["errors"][0]["code"].' - '.$response["errors"][0]["message"];
                        Log::logData($logFile, $mauticUser, $logMsg);
                    } else {
                        $logMsg = ' assegna utente a campagna: '.$id;
                        Log::logData($logFile, $mauticUser, $logMsg);
                    }
                }
            }
        }
    }

	/**
	 * Display form allows users to change setting for this subscription plan
	 *
	 * @param object $row
	 */
	private function drawSettingForm($row)
	{
		$auth = self::authConfig();
        $segments = self::getSegments();
        $mauticTags = self::getTags($auth);
        $mauticCampains = self::getCampains($auth);
        
        //carico i paramtri salvati
        $eventParams = self::getEventParams($row);
        $tagsIds = explode(',', $eventParams["tagsMautic"]);
        $tagsIds = array_filter($tagsIds);

        $campainsIds = $eventParams["campainsMautic"];

		?>

        <div class="container">
            <h1><i class="fas fa-mail-bulk"></i> Mautic</h1>
            <div class="row mb-4">
                <div class="col-12 col-md-8 offset-md-4">
                    <div class="form-check form-switch" >
                        <input 
                            class="form-check-input ms-1 me-4" 
                            name="attivazioneMautic"
                            type="checkbox" 
                            id="onoff"
                            style="transform: scale(1.7);"
                            <?php
                                if ($eventParams["attivazioneMautic"] == 'on') {
                                    echo 'checked';
                                }
                            ?>
                        >
                        <label class="form-check-label fw-bold fs-3" for="flexSwitchCheckDefault">Attivare l`integrazione con Mautic</label>
                    </div>                
                </div>
            </div>
            <!-- segmenti -->
            <div class="row mb-3">
                <div class="col-12 col-md-4">
                    <label for="segmentoMautic" class="form-label fw-bold">Seleziona il segmento da assegnare all'iscritto</label>
                </div>
                <div class="col-12 col-md-8">
                    <select id="segmentoMautic" name="segmentoMautic" class="form-select" aria-label="Default select example">
                        <?php
                        foreach ($segments as $segmento) {

                            if ($segmento->id == $eventParams["segmentoMautic"]) {
                                echo '<option selected value="'.$segmento->id.'">'.$segmento->name.'</option>';
                            } else {
                                echo '<option value="'.$segmento->id.'">'.$segmento->name.'</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>
            <!-- tags -->
            <div class="row mb-5">
                <div class="col-12 col-md-4">
                    <label for="TagsMautic" class="form-label fw-bold">Seleziona i tag da assegnare all'iscritto</label>
                </div>
                <div class="col-12 col-md-8">
                    <select id="TagsMautic" name="tagsMautic[]" multiple class="form-select" size="10" aria-label="size 3 select example">
                        <?php foreach ($mauticTags as $mauticTag) : ?>
                            <option 
                            <?php
                            foreach ($tagsIds as $tagId) {
                                if ($tagId == $mauticTag["tag"]) {
                                    echo 'selected';
                                }
                            }
                            ?>
                            value="<?php echo $mauticTag["tag"];?>"
                            ><?php echo $mauticTag["tag"];?></option>
                        <?php endforeach; ?>
                    </select> 
                </div>
            </div>
            <!-- campagne -->
            <div class="row">
                <div class="col-12 col-md-4">
                    <label for="campagneMautic" class="form-label fw-bold">Seleziona le campagne da assegnare all'iscritto</label>
                </div>
                <div class="col-12 col-md-8">
                    <select id="campainsMautic" name="campainsMautic[]" multiple class="form-select" size="10" aria-label="size 3 select example">
                    <?php foreach ($mauticCampains as $mauticCampain) : ?>
                        <option 
                        <?php
                        foreach ($campainsIds as $campainsId) {
                            if ($campainsId == $mauticCampain['id']) {
                                echo 'selected';
                            }
                        }
                        ?> 
                        value="<?php echo  $mauticCampain['id']; ?>"><?php echo  $mauticCampain['name']; ?></option>
                    <?php endforeach ?>
                    </select>
                </div>
            </div>
        </div>
        <hr>
        <h1><i class="fas fa-bug"></i> Debug</h1>
        <pre>

        </pre>
		<?php
	}

	/**
	 * Method to check to see whether the plugin should run
	 *
	 * @param EventbookingTableEvent $row
	 *
	 * @return bool
	 */
	private function canRun($row)
	{
		if ($this->app->isClient('site') && !$this->params->get('show_on_frontend'))
		{
			return false;
		}

		return true;
	}

    private function getSegments() {

        $baseUrl = $this->baseUrl;
        $accessKey = base64_encode($this->username . ':' . $this->password);

        $apiUrl = $baseUrl . '/api/segments?limit=200?orderBy=name';

        $http = HttpFactory::getHttp();
        $headers = [
            'Authorization' => 'Basic ' . $accessKey,
        ];

        $elencoSegmenti = $http->get($apiUrl, $headers);
        $elencoSegmentiObj = json_decode($elencoSegmenti->body);

        return $elencoSegmentiObj->lists;

    }

    private function getEventParams($row) {
        $event  = EventbookingHelperDatabase::getEvent($row->id);
        $params = new Registry($event->params);

        $eventParams = [
            'attivazioneMautic' => $params->get('attivazioneMautic'),
            'segmentoMautic' => $params->get('segmentoMautic'),
            'tagsMautic' => $params->get('tagsMautic'),
            'campainsMautic' => $params->get('campainsMautic')
        ];

        return $eventParams;
    }

    private function getTags($auth) {
        $api = new MauticApi();
        $tagApi = $api->newApi("tags", $auth, $this->baseUrl);
        $limit = 200;
        $orderBy = 'tag';
        $tags = $tagApi->getList($searchFilter, $start, $limit, $orderBy, $orderByDir, $publishedOnly, $minimal);

        return $tags["tags"];
    }

    private function getCampains($auth) {
        $api = new MauticApi();
        $campaignApi = $api->newApi("campaigns", $auth, $this->baseUrl);
        $limit = 200;
        $publishedOnly = 'true';
        $orderBy = 'name';
        $minimal = true;
        $campaigns = $campaignApi->getList($searchFilter, $start, $limit, $orderBy, $orderByDir, $publishedOnly, true);
        foreach ($campaigns["campaigns"] as $item) {
            if ($item["isPublished"] == true) {
                $mauticCampains[] = $item;
            }
        }
        
        return $mauticCampains;
    }

}
