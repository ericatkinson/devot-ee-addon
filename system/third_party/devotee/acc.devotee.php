<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * devot:ee Addon Accessory
 *
 * @package		ExpressionEngine
 * @subpackage	Add-ons
 * @category	Accessories
 * @author		Visual Chefs, LLC
 * @copyright	Copyright (c) 2011, Visual Chefs, LLC
 */
class Devotee_acc {
	
	/**
	 * Accessory information
	 */
	public $name = 'devot:ee';
	public $id = 'devot-ee';
	public $version = '0.1.0';
	public $description = 'Monitor your addons for updates';
	public $sections = array();
	
	/**
	 * @var		object
	 * @access	protected
	 */
	protected $EE;
	
	/**
	 * @var		array
	 * @access	protected
	 */
	protected $_addons = array();
	
	/**
	 * @var		string
	 * @access	protected
	 */
	protected $_cache_path;
	
	/**
	 * @var		int
	 * @access	protected
	 */
	protected $_cache_time;
	
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->EE =& get_instance();
		
		// set cache settings
		$this->_cache_path = APPPATH.'cache/devotee/';
		$this->_cache_time = 60*15; // 15 minutes
		
		// create cache folder if it doesn't exist
		if(!is_dir($this->_cache_path))
		{
			mkdir($this->_cache_path);
		}
	}
	
	/**
	 * Install accessory
	 */
	public function install()
	{
		return TRUE;
	}
	
	/**
	 * Update accessory
	 */
	public function update()
	{
		return TRUE;
	}
	
	/**
	 * Uninstall accessory
	 */
	public function uninstall()
	{
		return TRUE;
	}
	
	/**
	 * Set accessory sections
	 */
	public function set_sections()
	{
		$this->sections['Addon Information'] = $this->_get_addons();
	}
	
	/**
	 * Get installed addon information
	 *
	 * @access	protected
	 * @return	string
	 */
	protected function _get_addons()
	{
		$this->EE->load->helper('file');
		
		// cache file
		$cache_file = $this->_cache_path.'addons';
		
		// if cache is still good, use it
		if(file_exists($cache_file) AND filemtime($cache_file) > (time() - $this->_cache_time))
		{
			$updates = read_file($cache_file);
		}
		// fetch new content if cache expired
		else
		{
			$this->EE->load->helper('directory');
			$this->EE->load->library('addons');
			$this->EE->load->model('addons_model');
			$this->EE->load->library('api');
			
			// scan third_party folder
			$map = directory_map(PATH_THIRD, 2);
			
			// return if nothing found
			if($map === FALSE)
			{
				return 'No third-party addons were found.';
			}
			
			// get fieldtypes because the addons library doesn't give all the info
			$this->EE->api->instantiate('channel_fields');
			$fieldtypes = $this->EE->api_channel_fields->fetch_all_fieldtypes();
			
			// set third-party addons
			$third_party = array_intersect_key($this->EE->addons->_packages, $map);
			
			// get all installed addons
			$installed = array(
				'modules' => $this->EE->addons->get_installed('modules'),
				'plugins' => $this->EE->addons_model->get_plugins(),
				'extensions' => $this->EE->addons->get_installed('extensions'),
				'fieldtypes' => $this->EE->addons->get_installed('fieldtypes'),
				'accessories' => $this->EE->addons->get_installed('accessories')
			);
			
			foreach($third_party as $package => $types)
			{
				if(array_key_exists($package, $this->_addons))
				{
					continue;
				}
				
				// check for module
				if(array_key_exists($package, $installed['modules']))
				{
					$addon = $installed['modules'][$package];
					$this->_set_addon_info($package, $addon['name'], $addon['module_version'], $types);
				}
				// check for plugin
				elseif(array_key_exists($package, $installed['plugins']))
				{
					$addon = $installed['plugins'][$package];
					$this->_set_addon_info($package, $addon['pi_name'], $addon['pi_version'], $types);
				}
				// check for extension
				elseif(array_key_exists($package, $installed['extensions']))
				{
					$addon = $installed['extensions'][$package];
					$this->_set_addon_info($package, $addon['name'], $addon['version'], $types);
				}
				// check for fieldtype
				elseif(array_key_exists($package, $installed['fieldtypes']))
				{
					$addon = $fieldtypes[$package];
					$this->_set_addon_info($package, $addon['name'], $addon['version'], $types);
				}
				// check for accessory
				elseif(array_key_exists($package, $installed['accessories']))
				{
					$addon = $installed['accessories'][$package];
					
					// we need to load the class if not devotee to get more info
					if($package != 'devotee')
					{
						$acc_path = PATH_THIRD.strtolower($package).'/';
						$this->EE->load->add_package_path($acc_path, FALSE);
						$acc = new $addon['class']();
						$this->EE->load->remove_package_path($acc_path);
					}
					// if devotee accessory, we already have the info!
					else
					{
						$acc = array(
							'name' => $this->name,
							'version' => $this->version
						);
						$acc = (object) $acc;
					}
					
					$this->_set_addon_info($package, $acc->name, $acc->version, $types);
				}
			}
			
			$updates = $this->_get_updates();
			
			write_file($cache_file, $updates);
		}
		
		// return view
		return $this->EE->load->view('updates', array(
			'updates' => $this->json_decode($updates),
			'last_check' => filemtime($cache_file),
			'theme_url' => $this->EE->config->item('theme_folder_url') . 'third_party/devotee/'
		), TRUE);
	}
	
	/**
	 * Set addon info
	 *
	 * @param	string
	 * @param	string
	 * @param	string
	 * @param	array
	 * @access	protected
	 */
	protected function _set_addon_info($package, $name, $version, $types)
	{
		$this->_addons[$package] = array(
			'name' => $name,
			'version' => $version,
			'types' => array_keys($types)
		);
	}
	
	/**
	 * Get update info from API
	 *
	 * @access	protected
	 * @return	string
	 */
	protected function _get_updates()
	{
		$data = array(
			'data' => $this->_addons
		);
		
		$ch = curl_init('http://expressionmonitor.com:8080/updates');
		curl_setopt_array($ch, array(
			CURLOPT_POST => TRUE,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_POSTFIELDS => $this->json_encode($data),
			CURLOPT_HTTPHEADER => array(
				'Content-type: application/json'
			)
		));
		$response = curl_exec($ch);
		curl_close($ch);
		
		if( ! $response)
		{
			$response = $this->json_encode(array(
				'error' => 'The API could not be reached. Try again later.'
			));
		}
		
		return $response;
	}
	
	/**
	 * AJAX method for clearing cache and reloading the addons list
	 */
	public function process_refresh()
	{
		if(AJAX_REQUEST)
		{
			// delete cache
			$this->EE->functions->delete_directory(APPPATH.'cache/devotee');
			
			// output html from view
			echo $this->_get_addons();
			exit;
		}
	}
	
	/**
	 * Encodes JSON
	 *
	 * This was written because currently EE supports PHP versions lower than 5.2,
	 * which do not contain native JSON support.
	 *
	 * @param   mixed
	 * @return  string
	 * @access  protected
	 */
	protected function json_encode($content)
	{
		if( ! function_exists('json_encode'))
		{
			require_once PATH_THIRD.'devotee/lib/JSON.php';
			
			$json = new Services_JSON;
			
			return $json->encode($content);
		}
		
		return json_encode($content);
	}
	
	/**
	 * Decodes JSON
	 *
	 * This was written because currently EE supports PHP versions lower than 5.2,
	 * which do not contain native JSON support.
	 *
	 * @param   string
	 * @param   bool
	 * @return  mixed
	 * @access  protected
	 */
	protected function json_decode($content, $assoc = FALSE)
	{
		if( ! function_exists('json_decode'))
		{
			require_once PATH_THIRD.'devotee/lib/JSON.php';
			
			$json = ($assoc == TRUE) ? new Services_JSON(SERVICES_JSON_LOOSE_TYPE) : new Services_JSON;
			
			return $json->decode($content);
		}
		
		return json_decode($content, $assoc);
	}
	
}

/* End of file acc.ee_monitor.php */