<?php

namespace App\Form;

use App\Entity\User;
use App\Game\LifeForm;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Spielername',
                'attr' => ['autocomplete' => 'username', 'maxlength' => 30],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-Mail-Adresse',
                'help' => 'Wir schicken dir einen Link zur Bestätigung. Optional bekommst du später Warnungen bei Angriffen.',
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [new Assert\NotBlank(message: 'Bitte gib deine E-Mail-Adresse ein.')],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'first_options' => ['label' => 'Passwort', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Passwort wiederholen', 'attr' => ['autocomplete' => 'new-password']],
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte gib ein Passwort ein.'),
                    new Assert\Length(min: 8, max: 4096, minMessage: 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.'),
                ],
            ])
            // The life form is chosen at the start; it can be changed after 30 days at the earliest.
            ->add('lifeForm', EnumType::class, [
                'class' => LifeForm::class,
                'mapped' => false,
                'expanded' => true,
                'label' => 'Lebensform',
                'choice_label' => static fn (LifeForm $form): string => $form->label(),
                'data' => LifeForm::Settler,
                'constraints' => [new Assert\NotNull(message: 'Bitte wähle eine Lebensform.')],
            ])
            // The terms of use (AGB) must be accepted; the label with the links is rendered in the template.
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'label' => false,
                'constraints' => [new Assert\IsTrue(message: 'Bitte akzeptiere die Nutzungsbedingungen (AGB).')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
