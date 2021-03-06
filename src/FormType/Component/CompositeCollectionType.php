<?php
/**
 * Created by PhpStorm.
 * User: ypoon
 * Date: 19/7/2018
 * Time: 5:35 PM
 */

namespace App\FormType\Component;

use App\FormType\DataTransformer\SerialIndexTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CompositeCollectionType extends AbstractType {
	private $transformer;
	public function __construct(SerialIndexTransformer $transformer) {
		$this->transformer = $transformer;
	}

	public function buildForm(FormBuilderInterface $builder, array $options) {
		if ($options["force_serial_index"]){
			$builder->addModelTransformer($this->transformer);
		}
		parent::buildForm($builder, $options); // TODO: Change the autogenerated stub

	}

	public function getParent() {
		return CollectionType::class;
	}

	public function getBlockPrefix() {
		return "composite_collection";
	}

	public function configureOptions(OptionsResolver $resolver) {
		parent::configureOptions($resolver); // TODO: Change the autogenerated stub
		$resolver->setDefaults([
			"allow_add" => true,
			"allow_delete" => true,
			"prototype" => true,
			"force_serial_index" => false
		]);
	}


}