<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What is on board, for the spec sheet (`docs/data-model.md` §3.9).
 *
 * §3.9 requires that `vessels.specs.amenities` values "come from a fixed list
 * with EL/EN labels in lang files, so the widget can show icons". An enum is
 * that fixed list: free text here would mean the widget could not map a value
 * to an icon, and "WC" / "wc" / "Τουαλέτα" would be three amenities.
 *
 * The rest of `specs` stays free-form and unknown keys are preserved, so this
 * constrains only the one field the UI actually renders from.
 *
 * ## Why the list is long rather than minimal
 *
 * Every case here is a question a guest asks before booking a day on a boat in
 * Greece — is there shade, is there a toilet, can I charge my phone, is there
 * drinking water. An operator who cannot tick the thing that makes their boat
 * worth choosing will write it into the description, where nothing can filter
 * or icon it. The cost of a case is two lang lines; the cost of a missing one
 * is a booking that goes elsewhere.
 *
 * **Life jackets are deliberately absent.** They are compulsory on every vessel,
 * so listing them advertises the law rather than the boat. Children's jackets
 * are *not* guaranteed, and parents ask specifically, so that case exists.
 */
enum VesselAmenity: string
{
    use HasTranslatedLabel;

    // Comfort on deck.
    case ShadeCanopy = 'shade_canopy';
    case SunDeck = 'sun_deck';
    case AirConditioning = 'air_conditioning';
    case Cabin = 'cabin';
    case Wc = 'wc';

    // Water and swimming — the reason most of these trips are booked.
    case SwimLadder = 'swim_ladder';
    case FreshwaterShower = 'freshwater_shower';
    case SnorkellingGear = 'snorkelling_gear';
    case Paddleboard = 'paddleboard';
    case FishingGear = 'fishing_gear';
    case BeachTowels = 'beach_towels';

    // Food and drink.
    case Fridge = 'fridge';
    case DrinkingWater = 'drinking_water';
    case Galley = 'galley';
    case Barbecue = 'barbecue';
    case CoffeeMachine = 'coffee_machine';

    // Power and connectivity.
    case SoundSystem = 'sound_system';
    case UsbCharging = 'usb_charging';
    case Wifi = 'wifi';

    // Who the boat suits.
    case ChildLifeJackets = 'child_life_jackets';
    case WheelchairAccessible = 'wheelchair_accessible';
    case PetFriendly = 'pet_friendly';
}
