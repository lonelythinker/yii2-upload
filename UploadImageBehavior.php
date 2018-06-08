<?php

namespace lonelythinker\yii2\upload;

use Imagine\Image\ManipulatorInterface;
use Yii;
use yii\base\InvalidParamException;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\imagine\Image;
use yii\helpers\Html;

/**
 * UploadImageBehavior automatically uploads image, creates thumbnails and fills
 * the specified attribute with a value of the name of the uploaded image.
 *
 * To use UploadImageBehavior, insert the following code to your ActiveRecord class:
 *
 * ```php
 * use lonelythinker\yii2\upload\UploadImageBehavior;
 *
 * function behaviors()
 * {
 *     return [
 *         [
 *             'class' => UploadImageBehavior::className(),
 *             'attributes' => [
 *             		[
 *             			'attribute' => '<?php echo $column->name;?>',//属性名
 *             			'multiple' => <?php if(StringHelper::endsWith($column->name, '_imgs') || StringHelper::endsWith($column->name, '_files')):?>true<?php else :?>false<?php endif; ?>, //是否多文件上传
 * 					    'placeholder' => realpath(Yii::$app->params['upload']['path'] . '/uploads/default/<?= $tableName . '-' . $column->name.'.png' ?>') ? Yii::$app->params['upload']['path'] . '/uploads/default/<?= $tableName . '-' . $column->name.'.png' ?>' : Yii::$app->params['upload']['path'] . '/uploads/default/default.png',//默认图片
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
 * 			   'thumbPath' => Yii::$app->params['upload']['path'] . '/uploads/imgs/thumbs', //缩略图保存物理路径
 * 			   'thumbUrl' => Yii::$app->params['upload']['url'] . '/uploads/imgs/thumbs', //缩略图访问地址
 *			   'createThumbsOnSave' => true,//是否在保存时创建缩略图 默认true
 *			   'createThumbsOnRequest' => true,//是否在请求图片时创建缩略图 默认true
 *			   'thumbs' => [
 *			       'l' => ['width' => 1024, 'height' => null,'quality' => 90], //大图
 *				   'm' => ['width' => 640, 'height' => null,'quality' => 80], //中图
 *				   's' => ['width' => 320, 'height' => null,'quality' => 70],//小图
 *				   't' => ['width' => 96, 'height' => null,'quality' => 60], //缩略图
 *				   'p' => ['width' => 200, 'height' => null,'quality' => 100], //预览图
 *				],
 *         ],
 *     ];
 * }
 * ```
 *
 * @author lonelythinker <710366112@qq.com> <http://www.lonelythinker.cn>
 */
class UploadImageBehavior extends UploadBehavior
{
	/**
	 * @var string
	 */
	public $thumbPath = '';
	/**
	 * @var string
	 */
	public $thumbUrl = '';
    /**
     * @var boolean
     */
    public $createThumbsOnSave = true;
    /**
     * @var boolean
     */
    public $createThumbsOnRequest = false;
    /**
     * @var array
     */
    public $thumbs= [
    	'l' => ['width' => 1024, 'height' => null,'quality' => 90], //大图
    	'm' => ['width' => 640, 'height' => null,'quality' => 80], //中图
    	's' => ['width' => 320, 'height' => null,'quality' => 70],//小图
    	't' => ['width' => 96, 'height' => null,'quality' => 60], //缩略图
    	'p' => ['width' => 200, 'height' => null,'quality' => 100], //预览图
    ];
  

    /**
     * @inheritdoc
     */
    protected function afterUpload()
    {
        parent::afterUpload();
        $this->createThumbs();
    }

   /**
     * @throws \yii\base\InvalidParamException
     */
    protected function createThumbs($createAction = 'createThumbsOnSave')
    {
        foreach ($this->attributes as $attribute => $attributeConfig) {
            $createThumbsOnSave =  $this->getAttributeConfig($attributeConfig, 'createThumbsOnSave');
            $thumbs =  $this->getAttributeConfig($attributeConfig, 'thumbs');
            if($createThumbsOnSave && $thumbs){
                foreach ($thumbs as $profile => $config) {
                    $thumbPaths = $this->getOriginalThumbsPath($attribute, $profile);
                    if ($thumbPaths !== null) {
                        foreach ($thumbPaths as $path => $thumbPath) {
                            if (!FileHelper::createDirectory(dirname($thumbPath))) {
                                throw new InvalidParamException("Directory specified in 'thumbPath' attribute doesn't exist or cannot be created.");
                            }
                            if (is_file($path) && !is_file($thumbPath)) {
                                $this->generateImageThumb($config, $path, $thumbPath);
                            }
                        }
                    }
                }
            }
        }
    }
    
    
    
    /**
     * Get the relationship between the original path and path thumbnail
     * @param string $attribute
     * @param string $profile
     * @param boolean $old
     * @return array|null
     */
    protected function getOriginalThumbsPath($attribute, $profile = 't', $old = false){
        $fileName = $this->resolveFileName($attribute, $old);
        if($fileName){
            $pathList = [];
            if(is_array($fileName)){
                foreach ($fileName as $k => $fn) {
                    $path = $this->getSingleUploadPath($attribute, $fn);
                    if($path){
                         $pathList[$path] = $this->getSingleThumbUploadPath($attribute, $fn, $profile);;
                    }
                }
            }
            else{
                $path = $this->getSingleUploadPath($attribute, $fileName);
                if($path){
                    $pathList[$path] = $this->getSingleThumbUploadPath($attribute, $fileName, $profile);
                }
            }
            return $pathList;
        }
        return  null;
    }


   /**
     * Get the relationship between the original URL and URL thumbnail
     * @param string $attribute
     * @param string $profile
     * @param boolean $old
     * @return array|null
     */
    protected function getOriginalThumbsUrl($attribute, $profile = 't', $old = false){
        $fileName = $this->resolveFileName($attribute, $old);
        if($fileName){
            $urlList = [];
            if(is_array($fileName)){
                foreach ($fileName as $k => $fn) {
                    $url = $this->getSingleUploadUrl($attribute, $fn);
                    if($url){
                        $urlList[$url] = $this->getSingleThumbUploadUrl($attribute, $fn, $profile);
                    }
                }
            }
            else{
                $url = $this->getSingleUploadUrl($attribute, $fileName);
                if($url){
                    $pathList[$url] = $this->getSingleThumbUploadUrl($attribute, $fileName, $profile);
                }
            }
            return $pathList;
        }
        return  null;
    }
    
   /**
     * Get the relationship between the original path and thumbnail URL
     * @param string $attribute
     * @param string $profile
     * @param boolean $old
     * @return array|null
     */
    protected function getOriginalPathThumbsUrl($attribute, $profile = 't', $old = false){
        $fileName = $this->resolveFileName($attribute, $old);
        if($fileName){
            $pathList = [];
            if(is_array($fileName)){
                foreach ($fileName as $k => $fn) {
                    $path = $this->getSingleUploadPath($attribute, $fn);
                    if($path){
                         $pathList[$path] = $this->getSingleThumbUploadUrl($attribute, $fn, $profile);
                    }
                }
            }
            else{
                 $path = $this->getSingleUploadPath($attribute, $fileName);
                 if($path){
                     $pathList[$path] = $this->getSingleThumbUploadUrl($attribute, $fileName, $profile);
                 }
            }
            return $pathList;
        }
        return  null;
    }
    
    
    /**
     * Get single file path
     * @param string $fileName
     * @return string|null
     */
    public function getSingleUploadPath($attribute, $fileName)
    {
        if($fileName){
            $path = $this->getAttributeConfig($attribute, 'path');
            $path = $this->resolvePath($path);
            return Yii::getAlias($path  . $fileName);
        }
        return null;
    }
    
    /**
     * Get single thumbnail file path
     * @param string $fileName
     * @param string $profile
     * @return string|null
     */
    public function getSingleThumbUploadPath($attribute, $fileName, $profile = 't')
    {
        if($fileName){
            $thumbPath = $this->getAttributeConfig($attribute, 'thumbPath');
            if(!$thumbPath){
                $thumbPath = $this->getAttributeConfig($attribute, 'path');
            }
            $path = $this->resolvePath($thumbPath);
            $fileName = $this->getThumbFileName($fileName, $profile);
            return Yii::getAlias($path  . $fileName);
        }
        return  null;
    }
    
    /**
     * Get single file URL
     * @param string $fileName
     * @return string
     */
    public function getSingleUploadUrl($attribute, $fileName)
    {
        if($fileName){
            $url = $this->getAttributeConfig($attribute, 'url');
            $url = $this->resolvePath($url);
            return preg_match('/^(http|https):\/\/.*$/', $fileName) ? $fileName : Yii::getAlias($url  . $fileName);
        }
        return  '';
    }
    
    /**
     * Get a single thumbnail URL
     * @param string $fileName
     * @param string $profile
     * @return string
     */
    public function getSingleThumbUploadUrl($attribute, $fileName, $profile = 't')
    {
        if($fileName){
            $thumbUrl = $this->getAttributeConfig($attribute, 'thumbUrl');
            if(!$thumbUrl){
                $thumbUrl = $this->getAttributeConfig($attribute, 'url');
            }
            $url = $this->resolvePath($thumbUrl);
            $fileName = $this->getThumbFileName($fileName, $profile);
            return preg_match('/^(http|https):\/\/.*$/', $fileName) ? $fileName : Yii::getAlias($url  . $fileName);
        }
        return  '';
    }
    

    /**
     * Get the URL collection of the thumbnail
     * @param string $attribute
     * @param string $profile
     * @return array|null
     */
    public function getThumbUploadUrl($attribute, $profile = 't')
    {
        $fileName = $this->getAttributeValue($attribute, true);
        $multiple = $this->getAttributeConfig($attribute, 'multiple');
        $placeholder = $this->getAttributeConfig($attribute, 'placeholder');
        $thumbUploadUrl = [];
        $uploadConfig = Yii::$app->params['upload'];
        if(($uploadConfig['type'] == 'qiniu' || $uploadConfig['type'] == 'localQiniu') && $uploadConfig['qiniu']['enabled'] && $uploadConfig['qiniu']['domain']){
        	//从七牛上获取缩略图
        	$thumbs = $this->getAttributeConfig($attribute, 'thumbs');
            if($thumbs){
	        	$width = ArrayHelper::getValue($thumbs[$profile], 'width');
	        	$height = ArrayHelper::getValue($thumbs[$profile], 'height');
	        	$quality = ArrayHelper::getValue($thumbs[$profile], 'quality', 100);
	        	
	            $thumbPaths = $this->getOriginalThumbsPath($attribute, $profile);
	            if ($thumbPaths !== null){
	            	foreach ($thumbPaths as $path => $thumbPath){
	            		$dataImageInfo = file_get_contents((preg_match('/^http:\/\/.*$/', $uploadConfig['qiniu']['domain']) ? $uploadConfig['qiniu']['domain'] : 'http://' . $uploadConfig['qiniu']['domain']) . "/$fileName?imageInfo");
	            		$dataImageInfo=json_decode($dataImageInfo,true);
	            		if(isset($dataImageInfo['width']) && isset($dataImageInfo['height'])){
				        	if (!$width || !$height) {
				        		$ratio = $dataImageInfo['width'] / $dataImageInfo['height'];
				        		if ($width && $height) {
				        			$newHeight = ceil($width / $ratio);
				        			$newWidth = ceil($height * $ratio);
				        		}
				        		elseif ($width) {
				        			$newHeight = ceil($width / $ratio);
				        			$newWidth = $width;
				        		} else {
				        			$newWidth = ceil($height * $ratio);
				        			$newHeight = $height;
				        		}
				        		$thumbUploadUrl[] = (preg_match('/^http:\/\/.*$/', $uploadConfig['qiniu']['domain']) ? $uploadConfig['qiniu']['domain'] : 'http://' . $uploadConfig['qiniu']['domain']) . "/$fileName?imageView2/1/w/$newHeight/h/$newWidth";
				        	}
	            		}
	            	}
	            }
            }
        }elseif($uploadConfig['type'] == 'local'){
	        $createThumbsOnRequest = $this->getAttributeConfig($attribute, 'createThumbsOnRequest');
	        if ($fileName && $createThumbsOnRequest) {
	            $this->createThumbs('createThumbsOnRequest');
	        }
	        $thumbUrls = $this->getOriginalPathThumbsUrl($attribute, $profile ,true);
	        if($thumbUrls){
	            foreach ($thumbUrls as $path => $thumbUrl) {
            		if (is_file($path)) {
            			$thumbUploadUrl[] = $thumbUrl;
            		}elseif ($placeholder) {
            			$thumbUploadUrl[] = $this->getPlaceholderUrl($attribute, $profile);
            		}
	            }
	        }
        }
        if($multiple){
        	return !empty($thumbUploadUrl) ? $thumbUploadUrl : null;
        }
        else{
        	return !empty($thumbUploadUrl) ? $thumbUploadUrl[0] : null;
        }
    }

    /**
     * Get URL Placeholder
     * @param string $attribute
     * @param string $profile
     * @return string
     */
    protected function getPlaceholderUrl($attribute, $profile)
    {
        $placeholder =  $this->getAttributeConfig($attribute, 'placeholder');
        $thumbs = $this->getAttributeConfig($attribute, 'thumbs');
        if($placeholder){
            list ($path, $url) = Yii::$app->assetManager->publish($placeholder);
            $filename = basename($path);
            $thumb = $this->getThumbFileName($filename, $profile);
            $thumbPath = dirname($path) . DIRECTORY_SEPARATOR . $thumb;
            $thumbUrl = dirname($url)  . $thumb;
            if (!is_file($thumbPath)) {
                $this->generateImageThumb($thumbs[$profile], $path, $thumbPath);
            }
            return $thumbUrl;
        }
        return '';
    }

    
    /**
     * 
     * @param string $attribute
     * @param string $singleFileName
     */
    public function afterUnlink($attribute, $singleFileName){
        $thumbs = $this->getAttributeConfig($attribute, 'thumbs');
        if($singleFileName && $thumbs){
            foreach ($thumbs as $profile => $config) {
                $path = $this->getSingleThumbUploadPath($attribute, $singleFileName, $profile);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }
    

    /**
     * @param $filename
     * @param string $profile
     * @return string
     */
    protected function getThumbFileName($filename, $profile = 't')
    {
        return $profile . '-' . $filename;
    }

    /**
     * @param $config
     * @param $path
     * @param $thumbPath
     */
    protected function generateImageThumb($config, $path, $thumbPath)
    {
    	if($config){
	        $width = ArrayHelper::getValue($config, 'width');
	        $height = ArrayHelper::getValue($config, 'height');
	        $quality = ArrayHelper::getValue($config, 'quality', 100);
	        $mode = ArrayHelper::getValue($config, 'mode', ManipulatorInterface::THUMBNAIL_INSET);
	
	        if (!$width || !$height) {
	            $image = Image::getImagine()->open($path);
	            $ratio = $image->getSize()->getWidth() / $image->getSize()->getHeight();
	            if ($width) {
	                $height = ceil($width / $ratio);
	            } else {
	                $width = ceil($height * $ratio);
	            }
	        }
	
	        // Fix error "PHP GD Allowed memory size exhausted".
	        ini_set('memory_limit', '512M');
	        Image::thumbnail($path, $width, $height, $mode)->save($thumbPath, ['quality' => $quality]);
    	}
    }
    
    /**
     * Preview image
     * @param type $option
     * @return type
     */
    public function createPreview($option){
        $model = $this->owner;
        $attribute = $option['attribute'];
        $attrLabel = $model->getAttributeLabel($attribute);
        //'style' => 'max-width:160px;max-height:160px;'
        $imgOptions = ArrayHelper::getValue($option, 'imgOptions', ['class'=>'file-preview-image']);
        $imgOptions = array_merge(['alt'=> $attrLabel, 'title'=> $attrLabel], $imgOptions);
        $profile = ArrayHelper::getValue($option, 'profile');
        if($profile){
            $imgUrl = $this->getThumbUploadUrl($attribute, $profile);
        }
        else{
            $imgUrl = $this->getUploadUrl($attribute);
        }
        $previewList = [];
        if($imgUrl){
            if(is_array($imgUrl)){
                foreach ($imgUrl as $imgSrc) {
                    $previewList[] = Html::img($imgSrc, $imgOptions);
                }
            }
            else{
                $previewList[] = Html::img($imgUrl, $imgOptions);
            }
        }
        return $previewList;
    }
}
