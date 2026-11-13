<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** A new password after "Passwort vergessen" – same rules as at sign-up. */
class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Die Passwörter stimmen nicht überein.',
            'first_options' => ['label' => 'Neues Passwort', 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Passwort wiederholen', 'attr' => ['autocomplete' => 'new-password']],
            'constraints' => [
                new Assert\NotBlank(message: 'Bitte gib ein Passwort ein.'),
                new Assert\Length(min: 8, max: 4096, minMessage: 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.'),
            ],
        ]);
    }
}
