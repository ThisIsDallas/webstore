<?php

class PaymentsController extends AdminBaseController
{
	private $noneActive=0;

	public $controllerName = "Payments";

	public function accessRules()
	{
		return array(
			array('allow',
				'actions'=>array('index','module','newpromo','order','promocodes','promotasks','updatepromo'),
				'roles'=>array('admin'),
			),
		);
	}

	public function beforeAction($action)
	{

		$this->scanPayments();

		$arrModules =  Modules::model()->findAllByAttributes(array('category'=>'payment'),array('order'=>'module')); //Get active and inactive

		$menuSidebar = array();
		$menuSidebara = array();
		foreach ($arrModules as $module)
			try {
				if (Yii::app()->getComponent($module->module))
					if (Yii::app()->getComponent($module->module)->advancedMode)
						$menuSidebara[] = array('label'=>Yii::app()->getComponent($module->module)->AdminName, 'url'=>array('payments/module', 'id'=>$module->module));
					else
						$menuSidebar[] = array('label'=>Yii::app()->getComponent($module->module)->AdminName, 'url'=>array('payments/module', 'id'=>$module->module));
			}
			catch (Exception $e) {
				Yii::log("Missing widget ".$e, 'error', 'application.'.__CLASS__.".".__FUNCTION__);
			}

		$this->menuItems = array_merge(
			array(
				array('label'=>'Simple Integration Modules', 'linkOptions'=>array('class'=>'nav-header'))
				),
			$menuSidebar,
			array(
				array('label'=>'Advanced Integration Modules', 'linkOptions'=>array('class'=>'nav-header'))
			),
			$menuSidebara,
			array(
				array('label'=>'Payment Setup', 'linkOptions'=>array('class'=>'nav-header')),
				array('label'=>'Set Display Order', 'url'=>array('payments/order')),
				array('label'=>'Promo Codes', 'url'=>array('payments/promocodes')),
				array('label'=>'Promo Code Tasks', 'url'=>array('payments/promotasks')),

			)
		);


		$objModules = Modules::model()->findAllByAttributes(array('category'=>'payment','active'=>1));

		if (count($objModules)==0 && $action->id=="index")
		{
			$this->noneActive=1;
			Yii::app()->user->setFlash('error',Yii::t('cart','WARNING: You have no payment modules activated. No one can checkout.'));
		}

		return parent::beforeAction($action);
	}


	public function scanPayments()
	{

		$files=array_merge(
			glob(YiiBase::getPathOfAlias("ext.wspayment").'/*', GLOB_ONLYDIR),
			glob(YiiBase::getPathOfAlias("custom.extensions.payment").'/*', GLOB_ONLYDIR)
		);


		foreach ($files as $file)
		{

			$moduleName = mb_pathinfo($file,PATHINFO_BASENAME);

			//Check if module is already in database
			$objModule = Modules::LoadByName($moduleName);
			if(!($objModule instanceof Modules))
			{
				//The module doesn't exist, attempt to install it
				try {

					$objModule = new Modules();
					$objModule->active=0;
					$objModule->module = $moduleName;
					$objModule->category = 'payment';
					$objModule->configuration = Yii::app()->getComponent($moduleName)->getDefaultConfiguration();
					if (!$objModule->save())
						Yii::log("Found widget $moduleName could not install ".print_r($objModule->getErrors(),true), 'error', 'application.'.__CLASS__.".".__FUNCTION__);

				}
				catch (Exception $e) {
					Yii::log("Found widget $moduleName could not install ".$e, 'error', 'application.'.__CLASS__.".".__FUNCTION__);
				}

			} else  {
				$objModule->version = Yii::app()->getComponent($moduleName)->version;
				$objModule->name = Yii::app()->getComponent($moduleName)->AdminNameNormal;
				$objModule->save();
			}
		}





	}


	public function actionIndex()
	{
		$this->render("index");
	}


	public function actionOrder()
	{


		$order = Yii::app()->getRequest()->getPost('order');
		if (isset($order))
		{

			$arrOrder = explode(",",$order);
			Modules::model()->updateAll(array('sort_order'=>null),'category = :cat',array(':cat'=>'payment'));
			$ct = 1;
			foreach ($arrOrder as $id)
				Modules::model()->updateByPk($id,array('sort_order'=>$ct++));


		}
		else
		{
			Yii::app()->clientScript->registerCoreScript('jquery');
			Yii::app()->clientScript->registerCoreScript('jquery.ui');
			Yii::app()->clientScript->registerCssFile("http://code.jquery.com/ui/1.10.1/themes/base/jquery-ui.css");
			$this->registerAsset("js/sortable.js");

			$criteria=new CDbCriteria;
			$criteria->addCondition("category='payment'");
			$criteria->addCondition("active=1");
			$criteria->addCondition("name IS NOT NULL");
			$criteria->order = 'sort_order';

			$model = Modules::model()->findAll($criteria);
			$this->render("order",array('model'=>$model));
		}
	}

	public function actionPromocodes()
	{
		$model = new PromoCode();
		$model->lscodes="freeshipping:";


		$this->registerAsset("js/promoset.js");

		$this->render("promocodes", array('model'=>$model));

	}

	public function actionPromotasks()
	{

		$model = new PromotaskForm();

		if (isset($_POST['DeleteUsed']))
		{
			Yii::log("User clicked Delete all Used Promo Codes", 'error', 'application.'.__CLASS__.".".__FUNCTION__);
			Yii::app()->db->createCommand('DELETE FROM `xlsws_promo_code` WHERE `qty_remaining` = 0 AND module IS NULL')->execute();
			Yii::app()->user->setFlash('success',
				Yii::t('customer','Used promo codes have been deleted.'));

		}
		if (isset($_POST['DeleteExpired']))
		{
			Yii::log("User clicked Delete all Expired Promo Codes", 'error', 'application.'.__CLASS__.".".__FUNCTION__);
			Yii::app()->db->createCommand('DELETE FROM `xlsws_promo_code` WHERE module IS NULL AND valid_until IS NOT NULL AND
							date_format(coalesce(valid_until,\'2099-12-31\'),\'%Y-%m-%d\')<\''.date("Y-m-d").'\'')->execute();
			Yii::app()->user->setFlash('success',
				Yii::t('customer','Expired promo codes have been deleted.'));

		}
		if (isset($_POST['DeleteSingleUse']))
		{
			Yii::log("User clicked Delete all Single Use Promo Codes", 'error', 'application.'.__CLASS__.".".__FUNCTION__);
			Yii::app()->db->createCommand('DELETE FROM `xlsws_promo_code` WHERE `qty_remaining` = 0 or `qty_remaining` = 1 AND module IS NULL')->execute();
			Yii::app()->user->setFlash('success',
				Yii::t('customer','Single use promo codes have been deleted.'));

		}
		if (isset($_POST['DeleteEverything']))
		{
			Yii::log("User clicked Delete all Single Use Promo Codes", 'error', 'application.'.__CLASS__.".".__FUNCTION__);
			Yii::app()->db->createCommand('DELETE FROM `xlsws_promo_code` WHERE module IS NULL')->execute();
			Yii::app()->user->setFlash('success',
				Yii::t('customer','Single use promo codes have been deleted.'));

		}
		if (isset($_POST['buttonCreate']) && isset($_POST['PromotaskForm']))
		{
			$model->attributes = $_POST['PromotaskForm'];

			$strCodes = str_replace(",","\n",$model->createCodes);
			$strCodes = str_replace("\t","\n",$strCodes);
			$strCodes = str_replace("\r","",$strCodes);
			$arrCodes = explode("\n",$strCodes);

			$objCodeTemplate = PromoCode::model()->findByPk($model->existingCodes);

			$intFailures=0;
			$intSuccesses=0;

			foreach($arrCodes as $strCodeToCreate) {

				$strCodeToCreate = trim($strCodeToCreate);

				if (strlen($strCodeToCreate)>0) { //Since we may have blank lines, verify the code is legitimate
					$objNewCode = new PromoCode;
					$objNewCode->code = $strCodeToCreate;
					$objNewCode->qty_remaining = 1;
					$objNewCode->enabled = 1;
					$objNewCode->exception = $objCodeTemplate->exception;
					$objNewCode->type = $objCodeTemplate->type;
					$objNewCode->amount = $objCodeTemplate->amount;
					$objNewCode->valid_from = $objCodeTemplate->valid_from;
					$objNewCode->valid_until = $objCodeTemplate->valid_until;
					$objNewCode->lscodes = $objCodeTemplate->lscodes;
					$objNewCode->threshold = $objCodeTemplate->threshold;

					if ($objNewCode->save())
						$intSuccesses++;
					else
					{
						Yii::log("Error creating new code in batch ".print_r($objNewCode->getErrors(),true), 'error', 'application.'.__CLASS__.".".__FUNCTION__);
						$intFailures++;
					}
				}
			}

			Yii::app()->user->setFlash('success',$intSuccesses." codes created successfully.".
					($intFailures>0 ? " ".$intFailures." codes failed to save." : ""));



		}


		$formDefinition = $model->getFormCreate();
		$formDefinition2 = $model->getFormTasks();
		$this->render("promotasks", array('model'=>$model,'form'=>new CForm($formDefinition,$model),'form2'=>new CForm($formDefinition2,$model)));

	}

	public function actionUpdatepromo()
	{
		$pk = Yii::app()->getRequest()->getPost('pk');
		$name = Yii::app()->getRequest()->getPost('name');
		$value = Yii::app()->getRequest()->getPost('value');

		if ($name=='valid_from' && $value=='') $value=null;
		if ($name=='valid_until' && $value=='') $value=null;
		if ($name=='qty_remaining' && $value=='') $value=null;
		if ($name=='threshold' && $value=='') $value=null;

		if ($name=='code' && $value=='')
		{   PromoCode::model()->deleteByPk($pk); echo "delete"; }
		else
		{  	PromoCode::model()->updateByPk($pk,array($name=>$value)); echo "success"; }


	}



	public function actionNewpromo()
	{

		if(isset($_POST['PromoCode']))
		{
			$objPromo = new PromoCode();
			$objPromo->attributes = $_POST['PromoCode'];
			if ($objPromo->validate())
			{
				if($objPromo->save())
					echo "success";
				else echo print_r($objPromo->getErrors,true);
			}
			else
				echo print_r($objPromo->getErrors,true);


		}


	}


}