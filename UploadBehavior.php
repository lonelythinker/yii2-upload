<?php

namespace lonelythinker\yii2\upload;


use Closure;
use Yii;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\db\BaseActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;
use Qiniu\Storage\UploadManager;
use Qiniu\Auth;

/**
 * UploadBehavior automatically uploads file and fills the specified attribute
 * with a value of the name of the uploaded file.
 *
 * To use UploadBehavior, insert the following code to your ActiveRecord class:
 *
 * ```php
 * use lonelythinker\yii2\upload\UploadBehavior;
 *
 * function behaviors()
 * {
 *     return [
 *         [
 *             'class' => UploadBehavior::className(),
 *             'attributes' => [
 *             		[
 *             			'attribute' => '<?php echo $column->name;?>',//属性名
 *             			'multiple' => <?php if(StringHelper::endsWith($column->name, '_imgs') || StringHelper::endsWith($column->name, '_files')):?>true<?php else :?>false<?php endif; ?>, //是否多文件上传
 *             		],
 *             ],
 *             'path' => Yii::$app->params['upload']['path'] . '/files/',//保存物理路径
 *             'url' => Yii::$app->params['upload']['url'] . '/files/',//访问地址路径
 *             'scenarios' => ['insert', 'update','default'],//在该情景下启用配置
 *             'multipleSeparator' => '|',//文件名分隔符，会将文件保存到同一个字段中,默认'|'
 *             'nullValue' => '',
 *			   'instanceByName' => true,//是否通过自定义name获取上传文件实例,默认true
 *			   'generateNewName' => true,//是否自动生成文件名,默认true
 *			   'unlinkOnSave' => true,//保存成功时是否删除原来的文件,默认true
 *			   'deleteTempFile' => true,//是否上传时删除临时文件,默认true
 *         ],
 *     ];
 * }
 * ```
 *
 * @author lonelythinker <710366112@qq.com> <http://www.lonelythinker.cn>
 */
class UploadBehavior extends Behavior
{
    /**
     * @event Event an event that is triggered after a file is uploaded.
     */
    const EVENT_AFTER_UPLOAD = 'afterUpload';

    /**
     * @var string the attribute which holds the attachment.
     */
    public $attributes;
    /**
     * @var array the scenarios in which the behavior will be triggered
     */
    public $scenarios = [];
    /**
     * @var string the base path or path alias to the directory in which to save files.
     */
    public $path;
    /**
     * @var string the base URL or path alias for this file
     */
    public $url;
    /**
     * @var bool Getting file instance by name
     */
    public $instanceByName = true;
    /**
     * @var boolean|callable generate a new unique name for the file
     * set true or anonymous function takes the old filename and returns a new name.
     * @see self::generateFileName()
     */
    public $generateNewName = true;
    /**
     * @var boolean If `true` current attribute file will be deleted
     */
    public $unlinkOnSave = true;
    /**
     * @var boolean If `true` current attribute file will be deleted after model deletion.
     */
    public $unlinkOnDelete = true;
    /**
     * @var boolean $deleteTempFile whether to delete the temporary file after saving.
     */
    public $deleteTempFile = true;
   /**
     * multiple File name separator
     */
    public $multipleSeparator = '|';
    /**
     * When the value of null
     */
    public $nullValue = '';
    
    /**
     * @var UploadedFile the uploaded file instance.
     */
    private $_files;
    
    /**
     * Delete fileName
     */
    private $_deleteFileNames = [];

    /**
     * MIME types
     */
    private $_mimeTypes = [];

    /**
     * @inheritdoc
     */
    public function init()
    {
    	parent::init();
    	
    	if ($this->attributes === null) {
    		throw new InvalidConfigException('The "attribute" property must be set.');
    	}
    	if(!is_array($this->attributes)){
    		throw new InvalidConfigException('"attribute" expects the array');
    	}
    	if ($this->path === null) {
    		throw new InvalidConfigException('The "path" property must be set.');
    	}
    	if ($this->url === null) {
    		throw new InvalidConfigException('The "url" property must be set.');
    	}
    	$this->configInit();
    }
    
    /**
     * Initialization configuration
     * @throws InvalidConfigException
     */
    private function configInit(){
    	$attributes = [];
    	foreach ($this->attributes as $k => $v) {
    		$attribute = ArrayHelper::remove($v, 'attribute');
    		if($attribute){
    			$attributes[$attribute] = $v;
    		}
    		else{
    			throw new InvalidConfigException('Array must contain the key : attribute .');
    		}
    	}
    	$this->attributes = $attributes;
    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            BaseActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            BaseActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            BaseActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            BaseActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            BaseActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }
    
    /**
     * Gets the attribute configuration, does not exist then gets the global configuration
     * @param string $attribute
     * @param string $key
     * @return mixed
     */
    protected function getAttributeConfig($attribute, $key){
        
        if(is_array($attribute)){
            $attributeConfig = $attribute;
        }
        else{
            $attributeConfig = $this->attributes[$attribute];
        }
        if($key){
            if(isset($attributeConfig[$key])){
                return $attributeConfig[$key];
            }
            else{
                if(property_exists(static::className(), $key)){
                    return $this->$key;
                }
            }
        }
        return null;
    }

    /**
     * Whether there has current scenario
     * @param string $attribute
     * @return boolean
     */
    protected function hasScenario($attribute){
        if(is_array($attribute)){
            $attributeConfig = $attribute;
        }
        else{
            $attributeConfig = $this->attributes[$attribute];
        }
        $model = $this->owner;
        $scenario = $this->getAttributeConfig($attributeConfig, 'scenarios');
        if(in_array($model->scenario, $scenario)){
            return true;
        }
        return false;
    }

    /**
     * Returns attribute value
     * @param string $attribute
     * @param boolean $old
     * @return string
     */
    protected function getAttributeValue($attribute, $old = false)
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        return ($old === true) ? $model->getOldAttribute($attribute) : $model->$attribute;
    }

    /**
     * This method is invoked before validation starts.
     */
    public function beforeValidate()
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        $magicFile = Yii::getAlias('@yii/helpers/mimeTypes.php');
        if (!isset($this->_mimeTypes[$magicFile])) {
        	$this->_mimeTypes[$magicFile] = require($magicFile);
        	$this->_mimeTypes[$magicFile]['jpg'] = 'image/jpg';
        }
        $flipMimeTypes = array_flip($this->_mimeTypes[$magicFile]);
        $uploadConfig = Yii::$app->params['upload'];
        foreach ($this->attributes as $attribute => $attributeConfig) {
            if($this->hasScenario($attributeConfig)){
                $file = $this->getAttributeValue($attribute);
                if (!$this->validateFile($file)) {
                    $file = $this->getUploadInstance($attribute);
                }
                if(!isset($this->_files[$attribute]) && $this->validateFile($file)){
                    //$model->setAttribute($attribute, $file);
                    $this->_files[$attribute] = $file;
                }else{
                	$multipleSeparator = $this->getAttributeConfig($attribute, 'multipleSeparator');
                	$nullValue = $this->getAttributeConfig($attribute, 'nullValue');
                	$pathValue = $this->getAttributeConfig($attribute, 'path');
                	$attributeValue = $model->getAttribute($attribute);
                	if(isset($attributeValue) && !empty($attributeValue)){
                		$base64Files = [];
                		if(is_string($attributeValue)){
                			if(preg_match('/^\[.*\]$/', $attributeValue)){
                				$attributeValueArr = json_decode($attributeValue);
                			}else{
	                			$attributeValueArr[] = $attributeValue;
                			}
                		}elseif (is_array($attributeValue)){
                			$attributeValueArr = $attributeValue;
                		}
                		$is_base64_string = true;//是否是base64的字符串
                		if(isset($attributeValueArr)){
                			foreach ($attributeValueArr as $attributeValueItem){
                				if(isset($attributeValueItem) && !empty($attributeValueItem)){
	                				if($is_base64_string && false !== strpos($attributeValueItem,',')){//base64
	                					list($type, $data) = explode(',', $attributeValueItem);
	                					if(isset($type) && isset($data)){
	                						$type = mb_strtolower($type, 'UTF-8');
	                						if(($mimeType = explode(':', str_replace([':', ';'], ':', $type))) && count($mimeType) == 3 && array_key_exists($mimeType[1], $flipMimeTypes)){
	                							$extension = $flipMimeTypes[$mimeType[1]];
			                					$tempfile = crc32(md5(base64_decode($data))).'.'.$extension;
			                					if(false !== @file_put_contents($pathValue.'/'.$tempfile, base64_decode($data), true)){
			                						$base64Files[] = $tempfile;
			                						if($uploadConfig['type'] == 'qiniu' && $uploadConfig['qiniu']['enabled']){//上传到七牛
												$this->uploadBase64ToQiniu($tempfile, $pathValue.'/'.$tempfile);
			                						}elseif($uploadConfig['type'] == 'localQiniu' && $uploadConfig['qiniu']['enabled']){//上传到本地和七牛
			                							$this->uploadBase64ToQiniu($tempfile, $pathValue.'/'.$tempfile);
			                						}
			                					}
	                						}else{
	                							throw new InvalidConfigException("The file type '$type' is not supported");
	                						}
	                					}
	                				}else{//直接赋值
	                					$is_base64_string = false;
	                					$model->setAttribute($attribute, $attributeValueItem);
	                				}
                				}
                			}
                		}
                		if($is_base64_string){
	                		if(count($base64Files) > 0){
	                			$model->setAttribute($attribute, implode($multipleSeparator, $base64Files));
	                		}else{
	                			$model->setAttribute($attribute, $nullValue);
	                		}
                		}
                	}else{
                		$model->setAttribute($attribute, $nullValue);
                	}
                }
            }
        }
    }
    
    /**
     * This method is called at the beginning of inserting or updating a record.
     */
    public function beforeSave()
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        foreach ($this->attributes as $attribute => $attributeConfig) {
            if($this->hasScenario($attributeConfig)){
                if(isset($this->_files[$attribute]) && $this->validateFile($this->_files[$attribute])){
                    if (!$model->getIsNewRecord() && $model->isAttributeChanged($attribute)) {
                        if ($this->getAttributeConfig($attributeConfig,'unlinkOnSave') === true) {
                            $this->_deleteFileNames[$attribute] = $this->resolveFileName($attribute, true);
                        }
                    }
                }
                else{
                    //Protect attribute
                    //unset($model->$attribute);
                }
            }
            else{
                if(!$model->getIsNewRecord()  && $model->isAttributeChanged($attribute)){
                    if($this->getAttributeConfig($attributeConfig,'unlinkOnSave')){
                        $this->_deleteFileNames[$attribute] = $this->resolveFileName($attribute, true);
                    }
                }
            }
        }
        $this->fileSave();
    }
    
    /**
     * This method is called at the end of inserting or updating a record.
     * @throws \yii\base\InvalidParamException
     */
    public function afterSave(){
        $this->unlinkOnSave();
    }
    
    /**
     * Save file
     * @throws InvalidParamException
     */
    public function fileSave()
    {
        if($this->_files){
            foreach ($this->_files as $attribute => $file) {
                if($this->validateFile($file)){
                    $basePath = $this->getUploaddirectory($attribute);
                    if (is_string($basePath) && FileHelper::createDirectory($basePath)) {
                        $this->save($attribute, $file, $basePath);
                        $this->afterUpload();
                    } else {
                        throw new InvalidParamException("Directory specified in 'path' attribute doesn't exist or cannot be created.");
                    }
                }
            }
        }
    }
    
    /**
     * After delete file
     * @param string $attribute
     * @param string $singleFileName
     */
    public function afterUnlink($attribute, $singleFileName){
        
    }

    /**
     * Delete file
     */
    public function unlinkOnSave(){
        if($this->_deleteFileNames){
            foreach ($this->_deleteFileNames as $attribute => $fileName) {
                if($fileName){
                    $fileNames = [];
                    if(is_array($fileName)){
                        $fileNames = $fileName;
                    }
                    else{
                        $fileNames[] = $fileName;
                    }
                    $basePath = $this->getUploaddirectory($attribute);
                    foreach ($fileNames as $fn) {
                        $path = $basePath.'/'.$fn;
                        if(is_file($path)){
                            @unlink($path);
                        }
                        $this->afterUnlink($attribute, $fn);
                    }
                }
            }
        }
    }

    /**
     * This method is invoked before deleting a record.
     */
    public function afterDelete()
    {
        foreach ($this->attributes as $attribute => $attributeConfig) {
            $unlinkOnDelete = $this->getAttributeConfig($attributeConfig, 'unlinkOnDelete');
            if($unlinkOnDelete){
                $this->delete($attribute);
            }
        }
    }
    
    /**
     * Returns file path for the attribute.
     * @param string $attribute
     * @param boolean $old
     * @return string|array|null the file path.
     */
    public function getUploadPath($attribute, $old = false)
    {
        $fileName = $this->resolveFileName($attribute, $old);
        if(!$fileName){
            return $fileName;
        }
        $basePath = $this->getUploaddirectory($attribute);
        if(is_array($fileName)){
            foreach ($fileName as $k => $fn) {
                $fileName[$k] = $basePath.$fn;
            }
            return $fileName ? $fileName : null;;
        }
        return $fileName ? $basePath.$fileName : "";
    }
    
   /**
    * Returns file url for the attribute.
    * @param string $attribute
    * @return string|array|null the file url.
    */
    public function getUploadUrl($attribute)
    {
        $fileName = $this->resolveFileName($attribute, true);
        if(!$fileName){
            return $fileName;
        }
        $multiple = $this->getAttributeConfig($attribute,'multiple');
        $url = $this->getAttributeConfig($attribute, 'url');
        $url =  Yii::getAlias($this->resolvePath($url));
        $uploadConfig = Yii::$app->params['upload'];
        if(is_array($fileName)){
            foreach ($fileName as $k => $fn) {
                if($fn){
                	if(preg_match('/^(http|https):\/\/.*$/', $fn)){
                		$fileName[$k] = $fn;
                	}elseif(($uploadConfig['type'] == 'qiniu' || $uploadConfig['type'] == 'localQiniu') && $uploadConfig['qiniu']['enabled']){//从七牛拿文件
                		$fileName[$k] = $this->getFileFromQiniu($fn);
                	}else{
                		$fileName[$k] = $url.$fn;
                	}
                }
            }
            return $fileName ? $fileName : null;
        }
        if($fileName){
			if(preg_match('/^(http|https):\/\/.*$/', $fileName)){
                $fileName = $fileName;
			}elseif(($uploadConfig['type'] == 'qiniu' || $uploadConfig['type'] == 'localQiniu') && $uploadConfig['qiniu']['enabled']){//从七牛拿文件
            	$fileName = $this->getFileFromQiniu($fileName);
            }else{
                $fileName = $url.$fileName;
            }
            if($multiple){
                return [$fileName];
            }
            else{
                return $fileName;
            }
        }
        return null;
    }
    
    /**
     * Replaces all placeholders in path variable with corresponding values.
     */
    protected function resolvePath($path)
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        return preg_replace_callback('/{([^}]+)}/', function ($matches) use ($model) {
            $name = $matches[1];
            $attribute = ArrayHelper::getValue($model, $name);
            if (is_string($attribute) || is_numeric($attribute)) {
                return $attribute;
            } else {
                return $matches[0];
            }
        }, $path);
    }

   /**
     * Saves the uploaded file.
     * @param UploadedFile $file the uploaded file instance
     * @param string $path the file path used to save the uploaded file
     * @return boolean true whether the file is saved successfully
     */
    protected function save($attribute, $file, $path)
    {
        $model = $this->owner;
        $model->$attribute = '';
        try {
            $deleteTempFile = $this->getAttributeConfig($attribute, 'deleteTempFile');
            $multipleSeparator = $this->getAttributeConfig($attribute, 'multipleSeparator');
            $uploadConfig = Yii::$app->params['upload'];
            if(is_array($file)){
                foreach ($file as $f) {
                    $fileName = $this->getFileName($attribute, $f);
	                if($uploadConfig['type'] == 'local'){//上传到本地
	                	if($f->saveAs($path.$fileName, $deleteTempFile)){
	                		$model->$attribute .= $fileName.$multipleSeparator;
	                	}
	                }elseif($uploadConfig['type'] == 'qiniu' && $uploadConfig['qiniu']['enabled']){//上传到七牛
	                	if($this->uploadToQiniu($fileName, $f)){
	                		$model->$attribute .= $fileName.$multipleSeparator;
	                	}
	                }elseif($uploadConfig['type'] == 'localQiniu' && $uploadConfig['qiniu']['enabled']){//上传到本地和七牛
	                	if($this->uploadToQiniu($fileName, $f)){
	                		if($f->saveAs($path.$fileName, $deleteTempFile)){
	                			$model->$attribute .= $fileName.$multipleSeparator;
	                		}
	                	}
	                }else{
                		if($f->saveAs($path.$fileName, $deleteTempFile)){
                			$model->$attribute .= $fileName.$multipleSeparator;
                		}
	                }
                }
                $model->$attribute = trim($model->$attribute, $multipleSeparator);
            }
            else{
                $fileName = $this->getFileName($attribute, $file);
                if($uploadConfig['type'] == 'local'){//上传到本地
                	if($file->saveAs($path.$fileName, $deleteTempFile)){
                		$model->$attribute = $fileName;
                	}
                }elseif($uploadConfig['type'] == 'qiniu' && $uploadConfig['qiniu']['enabled']){//上传到七牛
                	if($this->uploadToQiniu($fileName, $file)){
                		$model->$attribute = $fileName;
                	}
                }elseif($uploadConfig['type'] == 'localQiniu' && $uploadConfig['qiniu']['enabled']){//上传到本地和七牛
                	if($this->uploadToQiniu($fileName, $file)){
	                	if($file->saveAs($path.$fileName, $deleteTempFile)){
	                		$model->$attribute = $fileName;
	                	}
                	}
                }else{
                	if($file->saveAs($path.$fileName, $deleteTempFile)){
                		$model->$attribute = $fileName;
                	}
                }
                $model->$attribute = $fileName;
            }
        } catch (\Exception $exc) {
            throw $exc;//new \Exception('File save exception');
        }
        return true;
    }

    /**
     * Deletes old file.
     * @param string $attribute
     * @param boolean $old
     */
    protected function delete($attribute, $old = false)
    {
        $fileName = $this->resolveFileName($attribute, $old);
        if($fileName){
            $fileNames = [];
            if(is_array($fileName)){
                $fileNames = $fileName;
            }
            else{
                $fileNames[] = $fileName;
            }
            $basePath = $this->getUploaddirectory($attribute);
            foreach ($fileNames as $fn) {
                $filePath = $basePath.$fn;
                if (is_file($filePath)) {
                    @unlink($filePath);
                    $this->afterUnlink($attribute, $fn);
                }
            }
        }
    }
    
   /**
     * Get the UploadedFile
     * @param string $attribute
     * @return UploadedFile|array
     */
    protected function getUploadInstance($attribute){
        $model = $this->owner;
        $multiple = $this->getAttributeConfig($attribute,'multiple');
        $instanceByName = $this->getAttributeConfig($attribute,'instanceByName');
        if ($instanceByName === true) {
            if($multiple){
                $file = UploadedFile::getInstancesByName($attribute);
            }
            else{
                $file = UploadedFile::getInstanceByName($attribute);
            }
        } else {
            if($multiple){
                $file = UploadedFile::getInstances($model, $attribute);
            }
            else{
                $file = UploadedFile::getInstance($model, $attribute);
            }
        }
        return $file;
    }

    /**
     * Verification file
     * @param UploadedFile|array $file
     * @return boolean
     */
    protected function validateFile($file){
        $files = [];
        if(is_array($file)){
            $files = $file;
        }
        else{
            $files[] = $file;
        }
        if(!$files){
            return false;
        }
        foreach ($files as $f) {
            if(!($f instanceof UploadedFile)){
                return false;
            }
        }
        return true;
    }

    /**
     * Get upload directory
     * @return string
     */
    protected function getUploaddirectory($attribute){
        $path = $this->getAttributeConfig($attribute, 'path');
        $path = $this->resolvePath($path);
        return Yii::getAlias($path);
    }
    
    /**
     * resolve file name
     * @param string $attribute
     * @param boolean $old
     * @return string|array
     */
    protected function resolveFileName($attribute, $old = false){
        $multiple = $this->getAttributeConfig($attribute, 'multiple');
        $multipleSeparator = $this->getAttributeConfig($attribute, 'multipleSeparator');
        $fileName = $this->getAttributeValue($attribute, $old);
        $fileName = trim($fileName, $multipleSeparator);
        if($fileName){
            if($multiple){
                if(false !== strpos($fileName, $multipleSeparator)){
                    return explode($multipleSeparator, $fileName);
                }
                else{
                    return [$fileName];
                }
            }
            else{
                return $fileName;
            }
        }
        return null;
    }
   
    /**
     * @param UploadedFile $file
     * @return string
     */
    protected function getFileName($attribute, $file)
    {
        $generateNewName = $this->getAttributeConfig($attribute, 'generateNewName');
        if ($generateNewName) {
            return $generateNewName instanceof Closure
                ? call_user_func($generateNewName, $file)
                : $this->generateFileName($file);
        } else {
            return $this->sanitize($file->name);
        }
    }

    /**
     * Replaces characters in strings that are illegal/unsafe for filename.
     *
     * #my*  unsaf<e>&file:name?".png
     *
     * @param string $filename the source filename to be "sanitized"
     * @return boolean string the sanitized filename
     */
    public static function sanitize($filename)
    {
        return str_replace([' ', '"', '\'', '&', '/', '\\', '?', '#'], '-', $filename);
    }

    /**
     * Generates random filename.
     * @param UploadedFile $file
     * @return string
     */
    protected function generateFileName($file)
    {
    	if(isset($file->tempName)){
    		return crc32(hash_file('md5', $file->tempName)) . '.'.$file->extension;
    	}
    	throw new \yii\base\Exception('Generates random filename successfully.');
    }

    /**
     * This method is invoked after uploading a file.
     * The default implementation raises the [[EVENT_AFTER_UPLOAD]] event.
     * You may override this method to do postprocessing after the file is uploaded.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    protected function afterUpload()
    {
        $this->owner->trigger(self::EVENT_AFTER_UPLOAD);
    }
    
    /**
     * 上传到七牛
     * @param String $key
     * @param UploadedFile $file
     */
    protected function uploadToQiniu($key, $file){
    	$uploadConfig = Yii::$app->params['upload'];
    	if(isset($uploadConfig['qiniu']['accessKey']) && isset($uploadConfig['qiniu']['secretKey']) && isset($uploadConfig['qiniu']['bucket'])){
    		$auth = new Auth($uploadConfig['qiniu']['accessKey'], $uploadConfig['qiniu']['secretKey']);
    		$token = $auth->uploadToken($uploadConfig['qiniu']['bucket']);
    		$upManager = new UploadManager();
    		list($ret, $error) = $upManager->put($token, $key, @file_get_contents($file->tempName));
    		if ($error !== null){
    			return false;
    		}else{
    			return true;
    		}
    	}
    }
    
    /**
     * 上传到七牛
     * @param String $key
     * @param File $file
     */
    protected function uploadBase64ToQiniu($key, $file){
    	$uploadConfig = Yii::$app->params['upload'];
    	if(isset($uploadConfig['qiniu']['accessKey']) && isset($uploadConfig['qiniu']['secretKey']) && isset($uploadConfig['qiniu']['bucket'])){
    		$auth = new Auth($uploadConfig['qiniu']['accessKey'], $uploadConfig['qiniu']['secretKey']);
    		$token = $auth->uploadToken($uploadConfig['qiniu']['bucket']);
    		$upManager = new UploadManager();
    		list($ret, $error) = $upManager->put($token, $key, @file_get_contents($file));
    		if ($error !== null){
    			return false;
    		}else{
    			return true;
    		}
    	}
    }
    
    /**
     * 从七牛拿文件
     * @param String $key
     */
    protected function getFileFromQiniu($key){
    	$uploadConfig = Yii::$app->params['upload'];
    	return (preg_match('/^http:\/\/.*$/', $uploadConfig['qiniu']['domain']) ? $uploadConfig['qiniu']['domain'] : 'http://'.$uploadConfig['qiniu']['domain']) . "/$key";
    }
}
