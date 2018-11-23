<?php
/**
 * Created by PhpStorm.
 * User: ypoon
 * Date: 23/11/2018
 * Time: 11:40 AM
 */

namespace App\FormType\Form\Core;

use App\Entity\Core\Ticketing\TicketingItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TicketingItemForm extends AbstractType{
    public function buildForm(FormBuilderInterface $builder, array $options) {
        $builder->add("name", TextType::class)
            ->add("description", TextType::class)
            ->add("price", NumberType::class)
            ->add("validTill", DateType::class, [
                "widget" => 'single_text',
                'input' => 'datetime_immutable'
            ])
            ->add("visitorCount", NumberType::class, [
                "disabled" => true
            ])
            ->add("visitorCountModification", NumberType::class)
            ->add("isActive", CheckboxType::class)
            ->add("isTraded", CheckboxType::class)
            ->add("createTime", DateTimeType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable'
            ])
            ->add("Submit", SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver) {
        $resolver->setDefaults([
            "data_class" => TicketingItem::class
        ]);
    }

}