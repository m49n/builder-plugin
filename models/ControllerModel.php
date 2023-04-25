<?php namespace RainLab\Builder\Models;

use Yaml;
use Lang;
use File;
use RainLab\Builder\Classes\ControllerBehaviorLibrary;
use RainLab\Builder\Classes\ControllerFileParser;
use RainLab\Builder\Classes\ControllerGenerator;
use RainLab\Builder\Models\ModelModel;
use RainLab\Builder\Classes\PluginCode;
use Symfony\Component\Yaml\Dumper as YamlDumper;
use ApplicationException;
use DirectoryIterator;
use SystemException;
use Exception;

/**
 * ControllerModel represents and manages plugin controllers.
 *
 * @package rainlab\builder
 * @author Alexey Bobkov, Samuel Georges
 */
class ControllerModel extends BaseModel
{
    /**
     * @var object controller
     */
    public $controller;

    /**
     * @var array behaviors
     */
    public $behaviors = [];

    /**
     * @var string baseModelClassName
     */
    public $baseModelClassName;

    /**
     * @var array permissions
     */
    public $permissions = [];

    /**
     * @var object menuItem
     */
    public $menuItem;

    /**
     * @var array fillable
     */
    protected static $fillable = [
        'controller',
        'behaviors',
        'baseModelClassName',
        'permissions',
        'menuItem'
    ];

    /**
     * @var array validationRules
     */
    protected $validationRules = [
        'controller' => ['regex:/^[A-Z]+[a-zA-Z0-9_]+$/']
    ];

    /**
     * load
     */
    public function load($controller)
    {
        if (!$this->validateFileName($controller)) {
            throw new SystemException("Invalid controller file name: {$controller}");
        }

        $this->controller = $this->trimExtension($controller);
        $this->loadControllerBehaviors();
        $this->exists = true;
    }

    /**
     * save
     */
    public function save()
    {
        if ($this->isNewModel()) {
            $this->generateController();
        }
        else {
            $this->saveController();
        }
    }

    /**
     * fill
     */
    public function fill(array $attributes)
    {
        parent::fill($attributes);

        if (!$this->isNewModel() && is_array($this->behaviors)) {
            foreach ($this->behaviors as $class => &$configuration) {
                if (is_scalar($configuration)) {
                    $configuration = json_decode($configuration, true);
                }
            }
        }
    }

    /**
     * listPluginControllers
     */
    public static function listPluginControllers($pluginCodeObj)
    {
        $controllersDirectoryPath = $pluginCodeObj->toPluginDirectoryPath().'/controllers';

        $controllersDirectoryPath = File::symbolizePath($controllersDirectoryPath);

        if (!File::isDirectory($controllersDirectoryPath)) {
            return [];
        }

        $result = [];
        foreach (new DirectoryIterator($controllersDirectoryPath) as $fileInfo) {
            if ($fileInfo->isDir()) {
                continue;
            }

            if ($fileInfo->getExtension() !== 'php') {
                continue;
            }

            $result[] =  $fileInfo->getBasename('.php');
        }

        return $result;
    }

    /**
     * getBaseModelClassNameOptions
     */
    public function getBaseModelClassNameOptions()
    {
        $models = ModelModel::listPluginModels($this->getPluginCodeObj());

        $result = [];
        foreach ($models as $model) {
            $result[$model->className] = $model->className;
        }

        return $result;
    }

    /**
     * getBehaviorsOptions
     */
    public function getBehaviorsOptions()
    {
        $library = ControllerBehaviorLibrary::instance();
        $behaviors = $library->listBehaviors();

        $result = [];
        foreach ($behaviors as $behaviorClass => $behaviorInfo) {
            $result[$behaviorClass] = [
                $behaviorInfo['name'],
                $behaviorInfo['description']
            ];
        }

        // Support for this is added via import tool
        unset($result[\Backend\Behaviors\ImportExportController::class]);

        return $result;
    }

    /**
     * getPermissionsOptions
     */
    public function getPermissionsOptions()
    {
        $model = new PermissionsModel();

        $model->loadPlugin($this->getPluginCodeObj()->toCode());

        $result = [];

        foreach ($model->permissions as $permissionInfo) {
            if (!isset($permissionInfo['label']) || !isset($permissionInfo['permission'])) {
                continue;
            }

            $result[$permissionInfo['permission']] = Lang::get($permissionInfo['label']);
        }

        return $result;
    }

    /**
     * getMenuItemOptions
     */
    public function getMenuItemOptions()
    {
        $model = new MenusModel();

        $model->loadPlugin($this->getPluginCodeObj()->toCode());

        $result = [];

        foreach ($model->menus as $itemInfo) {
            if (!isset($itemInfo['label']) || !isset($itemInfo['code'])) {
                continue;
            }

            $itemCode = $itemInfo['code'];
            $result[$itemCode] = Lang::get($itemInfo['label']);

            if (!isset($itemInfo['sideMenu'])) {
                continue;
            }

            foreach ($itemInfo['sideMenu'] as $itemInfo) {
                if (!isset($itemInfo['label']) || !isset($itemInfo['code'])) {
                    continue;
                }

                $subItemCode = $itemInfo['code'];

                $result[$itemCode.'||'.$subItemCode] = str_repeat('&nbsp;', 4).Lang::get($itemInfo['label']);
            }
        }

        return $result;
    }

    /**
     * getControllerFilePath
     */
    public function getControllerFilePath($controllerFilesDirectory = false)
    {
        $pluginCodeObj = $this->getPluginCodeObj();
        $controllersDirectoryPath = File::symbolizePath($pluginCodeObj->toPluginDirectoryPath().'/controllers');

        if (!$controllerFilesDirectory) {
            return $controllersDirectoryPath.'/'.$this->controller.'.php';
        }

        return $controllersDirectoryPath.'/'.strtolower($this->controller);
    }

    /**
     * getPluginRegistryData
     */
    public static function getPluginRegistryData($pluginCode, $subtype)
    {
        $pluginCodeObj = new PluginCode($pluginCode);
        $urlBase = $pluginCodeObj->toUrl().'/';

        $controllers = self::listPluginControllers($pluginCodeObj);
        $result = [];

        foreach ($controllers as $controller) {
            $controllerPath = strtolower(basename($controller));

            $url = $urlBase.$controllerPath;

            $result[$url] = $url;
        }

        return $result;
    }

    /**
     * saveController
     */
    protected function saveController()
    {
        $this->validate();

        $controllerPath = $this->getControllerFilePath();
        if (!File::isFile($controllerPath)) {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.controller.error_controller_not_found'));
        }

        if (!is_array($this->behaviors)) {
            throw new SystemException('The behaviors data should be an array.');
        }

        $fileContents = File::get($controllerPath);

        $parser = new ControllerFileParser($fileContents);

        $behaviors = $parser->listBehaviors();
        if (!$behaviors) {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.controller.error_controller_has_no_behaviors'));
        }

        $library = ControllerBehaviorLibrary::instance();
        foreach ($behaviors as $behaviorClass) {
            $behaviorInfo = $library->getBehaviorInfo($behaviorClass);

            if (!$behaviorInfo) {
                continue;
            }

            $propertyName = $behaviorInfo['configPropertyName'];
            $propertyValue = $parser->getStringPropertyValue($propertyName);
            if (!strlen($propertyValue)) {
                continue;
            }

            if (array_key_exists($behaviorClass, $this->behaviors)) {
                $this->saveBehaviorConfiguration($propertyValue, $this->behaviors[$behaviorClass], $behaviorClass);
            }
        }
    }

    /**
     * generateController
     */
    protected function generateController()
    {
        $this->validationMessages = [
            'controller.regex' => Lang::get('rainlab.builder::lang.controller.error_controller_name_invalid')
        ];

        $this->validationRules['controller'][] = 'required';

        $this->validate();

        $generator = new ControllerGenerator($this);
        $generator->generate();
    }

    /**
     * loadControllerBehaviors
     */
    protected function loadControllerBehaviors()
    {
        $filePath = $this->getControllerFilePath();
        if (!File::isFile($filePath)) {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.controller.error_controller_not_found'));
        }

        $fileContents = File::get($filePath);

        $parser = new ControllerFileParser($fileContents);

        $behaviors = $parser->listBehaviors();
        if (!$behaviors) {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.controller.error_controller_has_no_behaviors'));
        }

        $library = ControllerBehaviorLibrary::instance();
        $this->behaviors = [];
        foreach ($behaviors as $behaviorClass) {
            $behaviorInfo = $library->getBehaviorInfo($behaviorClass);
            if (!$behaviorInfo) {
                continue;
            }

            $propertyName = $behaviorInfo['configPropertyName'];
            $propertyValue = $parser->getStringPropertyValue($propertyName);
            if (!strlen($propertyValue)) {
                continue;
            }

            $configuration = $this->loadBehaviorConfiguration($propertyValue, $behaviorClass);
            if ($configuration === false) {
                continue;
            }

            $this->behaviors[$behaviorClass] = $configuration;
        }
    }

    /**
     * loadBehaviorConfiguration
     */
    protected function loadBehaviorConfiguration($fileName, $behaviorClass)
    {
        if (!preg_match('/^[a-z0-9\.\-_]+$/i', $fileName)) {
            return false;
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        if (strlen($extension) && $extension != 'yaml') {
            return false;
        }

        $controllerPath = $this->getControllerFilePath(true);
        $filePath = $controllerPath.'/'.$fileName;

        if (!File::isFile($filePath)) {
            return false;
        }

        try {
            $configuration = Yaml::parse(File::get($filePath));
            if ($behaviorClass === \Backend\Behaviors\ImportExportController::class) {
                $this->processImportExportConfig($configuration, true);
            }
            return $configuration;
        }
        catch (Exception $ex) {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.controller.error_invalid_yaml_configuration', ['file'=>$fileName]));
        }
    }

    /**
     * saveBehaviorConfiguration
     */
    protected function saveBehaviorConfiguration($fileName, $configuration, $behaviorClass)
    {
        if (!preg_match('/^[a-z0-9\.\-_]+$/i', $fileName)) {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.controller.error_invalid_config_file_name', ['file'=>$fileName, 'class'=>$behaviorClass]));
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        if (strlen($extension) && $extension != 'yaml') {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.controller.error_file_not_yaml', ['file'=>$fileName, 'class'=>$behaviorClass]));
        }

        $controllerPath = $this->getControllerFilePath(true);
        $filePath = $controllerPath.'/'.$fileName;

        $fileDirectory = dirname($filePath);
        if (!File::isDirectory($fileDirectory)) {
            if (!File::makeDirectory($fileDirectory, 0777, true, true)) {
                throw new ApplicationException(Lang::get('rainlab.builder::lang.common.error_make_dir', ['name'=>$fileDirectory]));
            }
        }

        if ($behaviorClass === \Backend\Behaviors\ImportExportController::class) {
            $this->processImportExportConfig($configuration);
        }

        $dumper = new YamlDumper();
        if ($configuration !== null) {
            $yamlData = $dumper->dump($configuration, 20, 0, false, true);
        }
        else {
            $yamlData = '';
        }

        if (@File::put($filePath, $yamlData) === false) {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.yaml.save_error', ['name'=>$filePath]));
        }

        @File::chmod($filePath);
    }

    /**
     * processImportExportConfig converts import. and export. keys to and from their config
     */
    protected function processImportExportConfig(array &$configuration, $isLoad = false)
    {
        if ($isLoad) {
            foreach ($configuration as $key => $value) {
                if (!is_array($value) || !in_array($key, ['import', 'export'])) {
                    continue;
                }

                foreach ($value as $k => $v) {
                    $configuration[$key.'.'.$k] = $v;
                }

                unset($configuration[$key]);
            }
        }
        else {
            foreach ($configuration as $key => $value) {
                if (starts_with($key, ['import.', 'export.'])) {
                    array_set($configuration, $key, array_pull($configuration, $key));
                }
            }
        }
    }

    /**
     * trimExtension
     */
    protected function trimExtension($fileName)
    {
        if (substr($fileName, -4) == '.php') {
            return substr($fileName, 0, -4);
        }

        return $fileName;
    }

    /**
     * validateFileName
     */
    protected function validateFileName($fileName)
    {
        if (!preg_match('/^[a-z0-9\.\-_]+$/i', $fileName)) {
            return false;
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        if (strlen($extension) && $extension != 'php') {
            return false;
        }

        return true;
    }
}
