<?php

declare(strict_types=1);

namespace App\Filament\App\Auth;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

/**
 * «Το προφίλ μου»: Filament's own profile page, plus how the person wants to be
 * greeted (product owner, 2026-09-17).
 *
 * «Προσφώνηση» is optional. When it is filled in, the home page greets them
 * with it; when it is empty, with their name exactly as it is written.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form->schema([
            $this->getNameFormComponent(),
            TextInput::make('salutation')
                ->label(__('panel.profile.salutation.label'))
                ->helperText(__('panel.profile.salutation.help'))
                ->maxLength(60),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $salutation = trim((string) ($data['salutation'] ?? ''));
        $data['salutation'] = $salutation === '' ? null : $salutation;

        return parent::mutateFormDataBeforeSave($data);
    }
}
