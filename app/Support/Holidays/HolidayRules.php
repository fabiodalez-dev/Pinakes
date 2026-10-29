<?php

declare(strict_types=1);

namespace App\Support\Holidays;

/**
 * The public holidays of each supported country, as rules (no dates).
 *
 * One rule = a stable id (unique in its country, "CC:id" globally), the
 * holiday's name in the country's own language, how its date is computed
 * and, optionally:
 *  - 'observed'  how a weekend date moves (see HolidayCalculator::observe());
 *  - 'from' / 'until'  the first / last year the holiday exists;
 *  - 'regions'   the regions (codes of the country's 'regions') it applies to;
 *                a rule without regions applies to the whole country;
 *  - 'optional'  customary rather than statutory, or valid only in part of
 *                the country: shown unticked until the librarian picks it;
 *  - 'overrides' year => Y-m-d, a one-year move decided by law (VE Day 2020).
 *
 * Rule kinds (all evaluated by HolidayCalculator::dateOf()):
 *  ['fixed', 'MM-DD']
 *  ['easter', offsetDays]                         Gregorian Easter Sunday ± n
 *  ['nth_weekday', month, 'mon', n, offset = 0]   n = 1..5, -1 = last
 *  ['weekday_on_or_before', 'MM-DD', 'mon']
 *  ['weekday_on_or_after', 'MM-DD', 'sat']
 *  ['fixed_if', 'MM-DD', ['fri'], <rule>]         that date on those weekdays,
 *                                                 otherwise the fallback rule
 *
 * The names are stored as written here (calendario_chiusure.descrizione) and
 * never go through __(): a German library wants "Fronleichnam" in German.
 * The lists follow the official rules known when they were written; the
 * library ticks the ones it closes for and can always add closures by hand.
 * Regional lists are not exhaustive where noted (CH: six cantons; AU: the
 * Western Australia King's Birthday is set by proclamation and is left out).
 */
final class HolidayRules
{
    /**
     * @return array<string, array{name: string, regions?: array<string, string>, region_required?: bool, rules: list<array<string, mixed>>}>
     */
    public static function countries(): array
    {
        return [
            'IT' => [
                'name' => 'Italia',
                'rules' => [
                    ['id' => 'new_year', 'name' => 'Capodanno', 'rule' => ['fixed', '01-01']],
                    ['id' => 'epiphany', 'name' => 'Epifania', 'rule' => ['fixed', '01-06']],
                    ['id' => 'easter', 'name' => 'Pasqua', 'rule' => ['easter', 0]],
                    ['id' => 'easter_monday', 'name' => "Lunedì dell'Angelo (Pasquetta)", 'rule' => ['easter', 1]],
                    ['id' => 'liberation', 'name' => 'Festa della Liberazione', 'rule' => ['fixed', '04-25']],
                    ['id' => 'labour', 'name' => 'Festa del Lavoro', 'rule' => ['fixed', '05-01']],
                    ['id' => 'republic', 'name' => 'Festa della Repubblica', 'rule' => ['fixed', '06-02']],
                    ['id' => 'ferragosto', 'name' => 'Ferragosto', 'rule' => ['fixed', '08-15']],
                    // Restored as a national holiday by the 2025 law, from 2026.
                    ['id' => 'san_francesco', 'name' => "San Francesco d'Assisi, patrono d'Italia", 'rule' => ['fixed', '10-04'], 'from' => 2026],
                    ['id' => 'all_saints', 'name' => 'Ognissanti', 'rule' => ['fixed', '11-01']],
                    ['id' => 'immaculate', 'name' => 'Immacolata Concezione', 'rule' => ['fixed', '12-08']],
                    ['id' => 'christmas', 'name' => 'Natale', 'rule' => ['fixed', '12-25']],
                    ['id' => 'st_stephen', 'name' => 'Santo Stefano', 'rule' => ['fixed', '12-26']],
                ],
            ],
            'US' => [
                'name' => 'United States',
                'rules' => [
                    ['id' => 'new_year', 'name' => "New Year's Day", 'rule' => ['fixed', '01-01'], 'observed' => 'us'],
                    ['id' => 'mlk_day', 'name' => 'Birthday of Martin Luther King, Jr.', 'rule' => ['nth_weekday', 1, 'mon', 3]],
                    ['id' => 'presidents_day', 'name' => "Washington's Birthday (Presidents' Day)", 'rule' => ['nth_weekday', 2, 'mon', 3]],
                    ['id' => 'memorial_day', 'name' => 'Memorial Day', 'rule' => ['nth_weekday', 5, 'mon', -1]],
                    ['id' => 'juneteenth', 'name' => 'Juneteenth National Independence Day', 'rule' => ['fixed', '06-19'], 'observed' => 'us', 'from' => 2021],
                    ['id' => 'independence_day', 'name' => 'Independence Day', 'rule' => ['fixed', '07-04'], 'observed' => 'us'],
                    ['id' => 'labor_day', 'name' => 'Labor Day', 'rule' => ['nth_weekday', 9, 'mon', 1]],
                    ['id' => 'columbus_day', 'name' => 'Columbus Day', 'rule' => ['nth_weekday', 10, 'mon', 2]],
                    ['id' => 'veterans_day', 'name' => 'Veterans Day', 'rule' => ['fixed', '11-11'], 'observed' => 'us'],
                    ['id' => 'thanksgiving', 'name' => 'Thanksgiving Day', 'rule' => ['nth_weekday', 11, 'thu', 4]],
                    ['id' => 'christmas', 'name' => 'Christmas Day', 'rule' => ['fixed', '12-25'], 'observed' => 'us'],
                ],
            ],
            'GB' => [
                'name' => 'United Kingdom',
                'regions' => ['ENG' => 'England and Wales', 'SCT' => 'Scotland', 'NIR' => 'Northern Ireland'],
                'region_required' => true,
                'rules' => [
                    ['id' => 'new_year', 'name' => "New Year's Day", 'rule' => ['fixed', '01-01'], 'observed' => 'next_monday', 'regions' => ['ENG', 'NIR']],
                    ['id' => 'new_year_sct', 'name' => "New Year's Day", 'rule' => ['fixed', '01-01'], 'observed' => 'pair', 'regions' => ['SCT']],
                    ['id' => 'second_january', 'name' => '2nd January', 'rule' => ['fixed', '01-02'], 'observed' => 'pair', 'regions' => ['SCT']],
                    ['id' => 'st_patrick', 'name' => "St Patrick's Day", 'rule' => ['fixed', '03-17'], 'observed' => 'next_monday', 'regions' => ['NIR']],
                    ['id' => 'good_friday', 'name' => 'Good Friday', 'rule' => ['easter', -2]],
                    ['id' => 'easter_monday', 'name' => 'Easter Monday', 'rule' => ['easter', 1], 'regions' => ['ENG', 'NIR']],
                    // Moved to Friday 8 May for the VE Day anniversaries.
                    ['id' => 'early_may', 'name' => 'Early May bank holiday', 'rule' => ['nth_weekday', 5, 'mon', 1], 'overrides' => [1995 => '1995-05-08', 2020 => '2020-05-08']],
                    // Moved for the Golden, Diamond and Platinum Jubilees.
                    ['id' => 'spring', 'name' => 'Spring bank holiday', 'rule' => ['nth_weekday', 5, 'mon', -1], 'overrides' => [2002 => '2002-06-04', 2012 => '2012-06-04', 2022 => '2022-06-02']],
                    ['id' => 'battle_of_the_boyne', 'name' => "Battle of the Boyne (Orangemen's Day)", 'rule' => ['fixed', '07-12'], 'observed' => 'next_monday', 'regions' => ['NIR']],
                    ['id' => 'summer', 'name' => 'Summer bank holiday', 'rule' => ['nth_weekday', 8, 'mon', -1], 'regions' => ['ENG', 'NIR']],
                    ['id' => 'summer_sct', 'name' => 'Summer bank holiday', 'rule' => ['nth_weekday', 8, 'mon', 1], 'regions' => ['SCT']],
                    ['id' => 'st_andrew', 'name' => "St Andrew's Day", 'rule' => ['fixed', '11-30'], 'observed' => 'next_monday', 'regions' => ['SCT']],
                    ['id' => 'christmas', 'name' => 'Christmas Day', 'rule' => ['fixed', '12-25'], 'observed' => 'pair'],
                    ['id' => 'boxing_day', 'name' => 'Boxing Day', 'rule' => ['fixed', '12-26'], 'observed' => 'pair'],
                ],
            ],
            'IE' => [
                'name' => 'Éire / Ireland',
                'rules' => [
                    ['id' => 'new_year', 'name' => "New Year's Day", 'rule' => ['fixed', '01-01'], 'observed' => 'next_monday'],
                    // First Monday of February, or 1 February when that is a Friday.
                    ['id' => 'st_brigid', 'name' => "St Brigid's Day", 'rule' => ['fixed_if', '02-01', ['fri'], ['nth_weekday', 2, 'mon', 1]], 'from' => 2023],
                    ['id' => 'st_patrick', 'name' => "St Patrick's Day", 'rule' => ['fixed', '03-17'], 'observed' => 'next_monday'],
                    ['id' => 'easter_monday', 'name' => 'Easter Monday', 'rule' => ['easter', 1]],
                    ['id' => 'may_day', 'name' => 'May Day', 'rule' => ['nth_weekday', 5, 'mon', 1]],
                    ['id' => 'june_holiday', 'name' => 'June Holiday', 'rule' => ['nth_weekday', 6, 'mon', 1]],
                    ['id' => 'august_holiday', 'name' => 'August Holiday', 'rule' => ['nth_weekday', 8, 'mon', 1]],
                    ['id' => 'october_holiday', 'name' => 'October Holiday', 'rule' => ['nth_weekday', 10, 'mon', -1]],
                    ['id' => 'christmas', 'name' => 'Christmas Day', 'rule' => ['fixed', '12-25'], 'observed' => 'pair'],
                    ['id' => 'st_stephen', 'name' => "St Stephen's Day", 'rule' => ['fixed', '12-26'], 'observed' => 'pair'],
                ],
            ],
            'DE' => [
                'name' => 'Deutschland',
                'regions' => [
                    'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg',
                    'HB' => 'Bremen', 'HH' => 'Hamburg', 'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern',
                    'NI' => 'Niedersachsen', 'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz', 'SL' => 'Saarland',
                    'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen',
                ],
                'rules' => [
                    ['id' => 'neujahr', 'name' => 'Neujahr', 'rule' => ['fixed', '01-01']],
                    ['id' => 'heilige_drei_koenige', 'name' => 'Heilige Drei Könige', 'rule' => ['fixed', '01-06'], 'regions' => ['BW', 'BY', 'ST']],
                    ['id' => 'frauentag', 'name' => 'Internationaler Frauentag', 'rule' => ['fixed', '03-08'], 'regions' => ['BE'], 'from' => 2019],
                    ['id' => 'frauentag_mv', 'name' => 'Internationaler Frauentag', 'rule' => ['fixed', '03-08'], 'regions' => ['MV'], 'from' => 2023],
                    ['id' => 'karfreitag', 'name' => 'Karfreitag', 'rule' => ['easter', -2]],
                    ['id' => 'ostersonntag', 'name' => 'Ostersonntag', 'rule' => ['easter', 0], 'regions' => ['BB']],
                    ['id' => 'ostermontag', 'name' => 'Ostermontag', 'rule' => ['easter', 1]],
                    ['id' => 'tag_der_arbeit', 'name' => 'Tag der Arbeit', 'rule' => ['fixed', '05-01']],
                    ['id' => 'christi_himmelfahrt', 'name' => 'Christi Himmelfahrt', 'rule' => ['easter', 39]],
                    ['id' => 'pfingstsonntag', 'name' => 'Pfingstsonntag', 'rule' => ['easter', 49], 'regions' => ['BB']],
                    ['id' => 'pfingstmontag', 'name' => 'Pfingstmontag', 'rule' => ['easter', 50]],
                    ['id' => 'fronleichnam', 'name' => 'Fronleichnam', 'rule' => ['easter', 60], 'regions' => ['BW', 'BY', 'HE', 'NW', 'RP', 'SL']],
                    ['id' => 'mariae_himmelfahrt', 'name' => 'Mariä Himmelfahrt', 'rule' => ['fixed', '08-15'], 'regions' => ['BY', 'SL']],
                    ['id' => 'weltkindertag', 'name' => 'Weltkindertag', 'rule' => ['fixed', '09-20'], 'regions' => ['TH'], 'from' => 2019],
                    ['id' => 'tag_der_deutschen_einheit', 'name' => 'Tag der Deutschen Einheit', 'rule' => ['fixed', '10-03']],
                    ['id' => 'reformationstag', 'name' => 'Reformationstag', 'rule' => ['fixed', '10-31'], 'regions' => ['BB', 'MV', 'SN', 'ST', 'TH']],
                    ['id' => 'reformationstag_nord', 'name' => 'Reformationstag', 'rule' => ['fixed', '10-31'], 'regions' => ['HB', 'HH', 'NI', 'SH'], 'from' => 2018],
                    ['id' => 'allerheiligen', 'name' => 'Allerheiligen', 'rule' => ['fixed', '11-01'], 'regions' => ['BW', 'BY', 'NW', 'RP', 'SL']],
                    // The Wednesday before 23 November.
                    ['id' => 'buss_und_bettag', 'name' => 'Buß- und Bettag', 'rule' => ['weekday_on_or_before', '11-22', 'wed'], 'regions' => ['SN']],
                    ['id' => 'erster_weihnachtstag', 'name' => '1. Weihnachtstag', 'rule' => ['fixed', '12-25']],
                    ['id' => 'zweiter_weihnachtstag', 'name' => '2. Weihnachtstag', 'rule' => ['fixed', '12-26']],
                ],
            ],
            'FR' => [
                'name' => 'France',
                'regions' => ['ALM' => 'Alsace-Moselle'],
                'rules' => [
                    ['id' => 'jour_de_l_an', 'name' => "Jour de l'an", 'rule' => ['fixed', '01-01']],
                    ['id' => 'vendredi_saint', 'name' => 'Vendredi saint', 'rule' => ['easter', -2], 'regions' => ['ALM']],
                    ['id' => 'lundi_de_paques', 'name' => 'Lundi de Pâques', 'rule' => ['easter', 1]],
                    ['id' => 'fete_du_travail', 'name' => 'Fête du Travail', 'rule' => ['fixed', '05-01']],
                    ['id' => 'victoire_1945', 'name' => 'Victoire 1945', 'rule' => ['fixed', '05-08']],
                    ['id' => 'ascension', 'name' => 'Ascension', 'rule' => ['easter', 39]],
                    ['id' => 'lundi_de_pentecote', 'name' => 'Lundi de Pentecôte', 'rule' => ['easter', 50]],
                    ['id' => 'fete_nationale', 'name' => 'Fête nationale', 'rule' => ['fixed', '07-14']],
                    ['id' => 'assomption', 'name' => 'Assomption', 'rule' => ['fixed', '08-15']],
                    ['id' => 'toussaint', 'name' => 'Toussaint', 'rule' => ['fixed', '11-01']],
                    ['id' => 'armistice', 'name' => 'Armistice 1918', 'rule' => ['fixed', '11-11']],
                    ['id' => 'noel', 'name' => 'Noël', 'rule' => ['fixed', '12-25']],
                    ['id' => 'saint_etienne', 'name' => 'Saint-Étienne', 'rule' => ['fixed', '12-26'], 'regions' => ['ALM']],
                ],
            ],
            'DK' => [
                'name' => 'Danmark',
                'rules' => [
                    ['id' => 'nytaarsdag', 'name' => 'Nytårsdag', 'rule' => ['fixed', '01-01']],
                    ['id' => 'skaertorsdag', 'name' => 'Skærtorsdag', 'rule' => ['easter', -3]],
                    ['id' => 'langfredag', 'name' => 'Langfredag', 'rule' => ['easter', -2]],
                    ['id' => 'paaskedag', 'name' => 'Påskedag', 'rule' => ['easter', 0]],
                    ['id' => 'anden_paaskedag', 'name' => '2. påskedag', 'rule' => ['easter', 1]],
                    // Abolished as a public holiday from 2024.
                    ['id' => 'store_bededag', 'name' => 'Store bededag', 'rule' => ['easter', 26], 'until' => 2023],
                    ['id' => 'kristi_himmelfartsdag', 'name' => 'Kristi himmelfartsdag', 'rule' => ['easter', 39]],
                    ['id' => 'pinsedag', 'name' => 'Pinsedag', 'rule' => ['easter', 49]],
                    ['id' => 'anden_pinsedag', 'name' => '2. pinsedag', 'rule' => ['easter', 50]],
                    ['id' => 'grundlovsdag', 'name' => 'Grundlovsdag', 'rule' => ['fixed', '06-05']],
                    ['id' => 'juleaftensdag', 'name' => 'Juleaftensdag', 'rule' => ['fixed', '12-24'], 'optional' => true],
                    ['id' => 'juledag', 'name' => 'Juledag', 'rule' => ['fixed', '12-25']],
                    ['id' => 'anden_juledag', 'name' => '2. juledag', 'rule' => ['fixed', '12-26']],
                    ['id' => 'nytaarsaftensdag', 'name' => 'Nytårsaftensdag', 'rule' => ['fixed', '12-31'], 'optional' => true],
                ],
            ],
            'ES' => [
                'name' => 'España',
                'rules' => [
                    ['id' => 'ano_nuevo', 'name' => 'Año Nuevo', 'rule' => ['fixed', '01-01']],
                    ['id' => 'epifania', 'name' => 'Epifanía del Señor', 'rule' => ['fixed', '01-06']],
                    // Regional in most autonomous communities: picked by the library.
                    ['id' => 'jueves_santo', 'name' => 'Jueves Santo', 'rule' => ['easter', -3], 'optional' => true],
                    ['id' => 'viernes_santo', 'name' => 'Viernes Santo', 'rule' => ['easter', -2]],
                    ['id' => 'lunes_de_pascua', 'name' => 'Lunes de Pascua', 'rule' => ['easter', 1], 'optional' => true],
                    ['id' => 'fiesta_del_trabajo', 'name' => 'Fiesta del Trabajo', 'rule' => ['fixed', '05-01']],
                    ['id' => 'asuncion', 'name' => 'Asunción de la Virgen', 'rule' => ['fixed', '08-15']],
                    ['id' => 'fiesta_nacional', 'name' => 'Fiesta Nacional de España', 'rule' => ['fixed', '10-12']],
                    ['id' => 'todos_los_santos', 'name' => 'Todos los Santos', 'rule' => ['fixed', '11-01']],
                    ['id' => 'constitucion', 'name' => 'Día de la Constitución Española', 'rule' => ['fixed', '12-06']],
                    ['id' => 'inmaculada', 'name' => 'Inmaculada Concepción', 'rule' => ['fixed', '12-08']],
                    ['id' => 'navidad', 'name' => 'Natividad del Señor', 'rule' => ['fixed', '12-25']],
                ],
            ],
            'PT' => [
                'name' => 'Portugal',
                'rules' => [
                    ['id' => 'ano_novo', 'name' => 'Ano Novo', 'rule' => ['fixed', '01-01']],
                    ['id' => 'carnaval', 'name' => 'Carnaval', 'rule' => ['easter', -47], 'optional' => true],
                    ['id' => 'sexta_feira_santa', 'name' => 'Sexta-feira Santa', 'rule' => ['easter', -2]],
                    ['id' => 'pascoa', 'name' => 'Páscoa', 'rule' => ['easter', 0]],
                    ['id' => 'dia_da_liberdade', 'name' => 'Dia da Liberdade', 'rule' => ['fixed', '04-25']],
                    ['id' => 'dia_do_trabalhador', 'name' => 'Dia do Trabalhador', 'rule' => ['fixed', '05-01']],
                    ['id' => 'corpo_de_deus', 'name' => 'Corpo de Deus', 'rule' => ['easter', 60]],
                    ['id' => 'dia_de_portugal', 'name' => 'Dia de Portugal, de Camões e das Comunidades Portuguesas', 'rule' => ['fixed', '06-10']],
                    ['id' => 'assuncao', 'name' => 'Assunção de Nossa Senhora', 'rule' => ['fixed', '08-15']],
                    ['id' => 'implantacao_da_republica', 'name' => 'Implantação da República', 'rule' => ['fixed', '10-05']],
                    ['id' => 'todos_os_santos', 'name' => 'Dia de Todos-os-Santos', 'rule' => ['fixed', '11-01']],
                    ['id' => 'restauracao', 'name' => 'Restauração da Independência', 'rule' => ['fixed', '12-01']],
                    ['id' => 'imaculada_conceicao', 'name' => 'Imaculada Conceição', 'rule' => ['fixed', '12-08']],
                    ['id' => 'natal', 'name' => 'Natal', 'rule' => ['fixed', '12-25']],
                ],
            ],
            'NL' => [
                'name' => 'Nederland',
                'rules' => [
                    ['id' => 'nieuwjaarsdag', 'name' => 'Nieuwjaarsdag', 'rule' => ['fixed', '01-01']],
                    ['id' => 'goede_vrijdag', 'name' => 'Goede Vrijdag', 'rule' => ['easter', -2], 'optional' => true],
                    ['id' => 'eerste_paasdag', 'name' => 'Eerste Paasdag', 'rule' => ['easter', 0]],
                    ['id' => 'tweede_paasdag', 'name' => 'Tweede Paasdag', 'rule' => ['easter', 1]],
                    // 27 April, the Saturday before when the 27th is a Sunday.
                    ['id' => 'koningsdag', 'name' => 'Koningsdag', 'rule' => ['fixed', '04-27'], 'observed' => 'sunday_back', 'from' => 2014],
                    ['id' => 'bevrijdingsdag', 'name' => 'Bevrijdingsdag', 'rule' => ['fixed', '05-05'], 'optional' => true],
                    ['id' => 'hemelvaartsdag', 'name' => 'Hemelvaartsdag', 'rule' => ['easter', 39]],
                    ['id' => 'eerste_pinksterdag', 'name' => 'Eerste Pinksterdag', 'rule' => ['easter', 49]],
                    ['id' => 'tweede_pinksterdag', 'name' => 'Tweede Pinksterdag', 'rule' => ['easter', 50]],
                    ['id' => 'eerste_kerstdag', 'name' => 'Eerste Kerstdag', 'rule' => ['fixed', '12-25']],
                    ['id' => 'tweede_kerstdag', 'name' => 'Tweede Kerstdag', 'rule' => ['fixed', '12-26']],
                ],
            ],
            'BE' => [
                'name' => 'België / Belgique',
                'rules' => [
                    ['id' => 'nieuwjaar', 'name' => "Nieuwjaar / Jour de l'An", 'rule' => ['fixed', '01-01']],
                    ['id' => 'pasen', 'name' => 'Pasen / Pâques', 'rule' => ['easter', 0]],
                    ['id' => 'paasmaandag', 'name' => 'Paasmaandag / Lundi de Pâques', 'rule' => ['easter', 1]],
                    ['id' => 'dag_van_de_arbeid', 'name' => 'Dag van de Arbeid / Fête du Travail', 'rule' => ['fixed', '05-01']],
                    ['id' => 'hemelvaart', 'name' => 'O.L.H. Hemelvaart / Ascension', 'rule' => ['easter', 39]],
                    ['id' => 'pinksteren', 'name' => 'Pinksteren / Pentecôte', 'rule' => ['easter', 49]],
                    ['id' => 'pinkstermaandag', 'name' => 'Pinkstermaandag / Lundi de Pentecôte', 'rule' => ['easter', 50]],
                    ['id' => 'nationale_feestdag', 'name' => 'Nationale feestdag / Fête nationale', 'rule' => ['fixed', '07-21']],
                    ['id' => 'olv_hemelvaart', 'name' => 'O.L.V. Hemelvaart / Assomption', 'rule' => ['fixed', '08-15']],
                    ['id' => 'allerheiligen', 'name' => 'Allerheiligen / Toussaint', 'rule' => ['fixed', '11-01']],
                    ['id' => 'wapenstilstand', 'name' => 'Wapenstilstand / Armistice', 'rule' => ['fixed', '11-11']],
                    ['id' => 'kerstmis', 'name' => 'Kerstmis / Noël', 'rule' => ['fixed', '12-25']],
                ],
            ],
            'AT' => [
                'name' => 'Österreich',
                'rules' => [
                    ['id' => 'neujahr', 'name' => 'Neujahr', 'rule' => ['fixed', '01-01']],
                    ['id' => 'heilige_drei_koenige', 'name' => 'Heilige Drei Könige', 'rule' => ['fixed', '01-06']],
                    ['id' => 'ostermontag', 'name' => 'Ostermontag', 'rule' => ['easter', 1]],
                    ['id' => 'staatsfeiertag', 'name' => 'Staatsfeiertag', 'rule' => ['fixed', '05-01']],
                    ['id' => 'christi_himmelfahrt', 'name' => 'Christi Himmelfahrt', 'rule' => ['easter', 39]],
                    ['id' => 'pfingstmontag', 'name' => 'Pfingstmontag', 'rule' => ['easter', 50]],
                    ['id' => 'fronleichnam', 'name' => 'Fronleichnam', 'rule' => ['easter', 60]],
                    ['id' => 'mariae_himmelfahrt', 'name' => 'Mariä Himmelfahrt', 'rule' => ['fixed', '08-15']],
                    ['id' => 'nationalfeiertag', 'name' => 'Nationalfeiertag', 'rule' => ['fixed', '10-26']],
                    ['id' => 'allerheiligen', 'name' => 'Allerheiligen', 'rule' => ['fixed', '11-01']],
                    ['id' => 'mariae_empfaengnis', 'name' => 'Mariä Empfängnis', 'rule' => ['fixed', '12-08']],
                    ['id' => 'christtag', 'name' => 'Christtag', 'rule' => ['fixed', '12-25']],
                    ['id' => 'stefanitag', 'name' => 'Stefanitag', 'rule' => ['fixed', '12-26']],
                ],
            ],
            'CH' => [
                'name' => 'Schweiz / Suisse / Svizzera',
                // Not exhaustive: six cantons in this first list.
                'regions' => ['BE' => 'Bern', 'BS' => 'Basel-Stadt', 'GE' => 'Genève', 'TI' => 'Ticino', 'VD' => 'Vaud', 'ZH' => 'Zürich'],
                'rules' => [
                    ['id' => 'neujahr', 'name' => 'Neujahr / Nouvel An / Capodanno', 'rule' => ['fixed', '01-01']],
                    ['id' => 'berchtoldstag', 'name' => 'Berchtoldstag / Saint-Berthold', 'rule' => ['fixed', '01-02'], 'regions' => ['BE', 'VD', 'ZH']],
                    ['id' => 'epifania', 'name' => 'Epifania', 'rule' => ['fixed', '01-06'], 'regions' => ['TI']],
                    ['id' => 'san_giuseppe', 'name' => 'San Giuseppe', 'rule' => ['fixed', '03-19'], 'regions' => ['TI']],
                    ['id' => 'karfreitag', 'name' => 'Karfreitag / Vendredi saint', 'rule' => ['easter', -2], 'regions' => ['BE', 'BS', 'GE', 'VD', 'ZH']],
                    ['id' => 'ostermontag', 'name' => 'Ostermontag / Lundi de Pâques / Lunedì di Pasqua', 'rule' => ['easter', 1], 'regions' => ['BE', 'BS', 'GE', 'TI', 'VD', 'ZH']],
                    ['id' => 'tag_der_arbeit', 'name' => 'Tag der Arbeit / Festa del lavoro', 'rule' => ['fixed', '05-01'], 'regions' => ['BS', 'TI', 'ZH']],
                    ['id' => 'auffahrt', 'name' => 'Auffahrt / Ascension / Ascensione', 'rule' => ['easter', 39]],
                    ['id' => 'pfingstmontag', 'name' => 'Pfingstmontag / Lundi de Pentecôte / Lunedì di Pentecoste', 'rule' => ['easter', 50], 'regions' => ['BE', 'BS', 'GE', 'TI', 'VD', 'ZH']],
                    ['id' => 'corpus_domini', 'name' => 'Corpus Domini', 'rule' => ['easter', 60], 'regions' => ['TI']],
                    ['id' => 'santi_pietro_e_paolo', 'name' => 'Santi Pietro e Paolo', 'rule' => ['fixed', '06-29'], 'regions' => ['TI']],
                    ['id' => 'bundesfeier', 'name' => 'Bundesfeier / Fête nationale / Festa nazionale', 'rule' => ['fixed', '08-01']],
                    ['id' => 'assunzione', 'name' => 'Assunzione', 'rule' => ['fixed', '08-15'], 'regions' => ['TI']],
                    // The Thursday after the first Sunday of September.
                    ['id' => 'jeune_genevois', 'name' => 'Jeûne genevois', 'rule' => ['nth_weekday', 9, 'sun', 1, 4], 'regions' => ['GE']],
                    // The Monday after the third Sunday of September.
                    ['id' => 'lundi_du_jeune', 'name' => 'Lundi du Jeûne fédéral', 'rule' => ['nth_weekday', 9, 'sun', 3, 1], 'regions' => ['VD']],
                    ['id' => 'ognissanti', 'name' => 'Ognissanti', 'rule' => ['fixed', '11-01'], 'regions' => ['TI']],
                    ['id' => 'immacolata', 'name' => 'Immacolata', 'rule' => ['fixed', '12-08'], 'regions' => ['TI']],
                    ['id' => 'weihnachten', 'name' => 'Weihnachten / Noël / Natale', 'rule' => ['fixed', '12-25']],
                    ['id' => 'stephanstag', 'name' => 'Stephanstag / Santo Stefano', 'rule' => ['fixed', '12-26'], 'regions' => ['BE', 'BS', 'TI', 'ZH']],
                    ['id' => 'restauration', 'name' => 'Restauration de la République', 'rule' => ['fixed', '12-31'], 'regions' => ['GE']],
                ],
            ],
            'SE' => [
                'name' => 'Sverige',
                'rules' => [
                    ['id' => 'nyarsdagen', 'name' => 'Nyårsdagen', 'rule' => ['fixed', '01-01']],
                    ['id' => 'trettondedag_jul', 'name' => 'Trettondedag jul', 'rule' => ['fixed', '01-06']],
                    ['id' => 'langfredagen', 'name' => 'Långfredagen', 'rule' => ['easter', -2]],
                    ['id' => 'paskdagen', 'name' => 'Påskdagen', 'rule' => ['easter', 0]],
                    ['id' => 'annandag_pask', 'name' => 'Annandag påsk', 'rule' => ['easter', 1]],
                    ['id' => 'forsta_maj', 'name' => 'Första maj', 'rule' => ['fixed', '05-01']],
                    ['id' => 'kristi_himmelsfardsdag', 'name' => 'Kristi himmelsfärdsdag', 'rule' => ['easter', 39]],
                    ['id' => 'pingstdagen', 'name' => 'Pingstdagen', 'rule' => ['easter', 49]],
                    ['id' => 'nationaldagen', 'name' => 'Sveriges nationaldag', 'rule' => ['fixed', '06-06']],
                    ['id' => 'midsommarafton', 'name' => 'Midsommarafton', 'rule' => ['weekday_on_or_after', '06-19', 'fri'], 'optional' => true],
                    ['id' => 'midsommardagen', 'name' => 'Midsommardagen', 'rule' => ['weekday_on_or_after', '06-20', 'sat']],
                    ['id' => 'alla_helgons_dag', 'name' => 'Alla helgons dag', 'rule' => ['weekday_on_or_after', '10-31', 'sat']],
                    ['id' => 'julafton', 'name' => 'Julafton', 'rule' => ['fixed', '12-24'], 'optional' => true],
                    ['id' => 'juldagen', 'name' => 'Juldagen', 'rule' => ['fixed', '12-25']],
                    ['id' => 'annandag_jul', 'name' => 'Annandag jul', 'rule' => ['fixed', '12-26']],
                    ['id' => 'nyarsafton', 'name' => 'Nyårsafton', 'rule' => ['fixed', '12-31'], 'optional' => true],
                ],
            ],
            'NO' => [
                'name' => 'Norge',
                'rules' => [
                    ['id' => 'forste_nyttarsdag', 'name' => '1. nyttårsdag', 'rule' => ['fixed', '01-01']],
                    ['id' => 'skjaertorsdag', 'name' => 'Skjærtorsdag', 'rule' => ['easter', -3]],
                    ['id' => 'langfredag', 'name' => 'Langfredag', 'rule' => ['easter', -2]],
                    ['id' => 'forste_paskedag', 'name' => '1. påskedag', 'rule' => ['easter', 0]],
                    ['id' => 'andre_paskedag', 'name' => '2. påskedag', 'rule' => ['easter', 1]],
                    ['id' => 'arbeidernes_dag', 'name' => 'Arbeidernes dag', 'rule' => ['fixed', '05-01']],
                    ['id' => 'grunnlovsdag', 'name' => 'Grunnlovsdag', 'rule' => ['fixed', '05-17']],
                    ['id' => 'kristi_himmelfartsdag', 'name' => 'Kristi himmelfartsdag', 'rule' => ['easter', 39]],
                    ['id' => 'forste_pinsedag', 'name' => '1. pinsedag', 'rule' => ['easter', 49]],
                    ['id' => 'andre_pinsedag', 'name' => '2. pinsedag', 'rule' => ['easter', 50]],
                    ['id' => 'forste_juledag', 'name' => '1. juledag', 'rule' => ['fixed', '12-25']],
                    ['id' => 'andre_juledag', 'name' => '2. juledag', 'rule' => ['fixed', '12-26']],
                ],
            ],
            'FI' => [
                'name' => 'Suomi',
                'rules' => [
                    ['id' => 'uudenvuodenpaiva', 'name' => 'Uudenvuodenpäivä', 'rule' => ['fixed', '01-01']],
                    ['id' => 'loppiainen', 'name' => 'Loppiainen', 'rule' => ['fixed', '01-06']],
                    ['id' => 'pitkaperjantai', 'name' => 'Pitkäperjantai', 'rule' => ['easter', -2]],
                    ['id' => 'paasiaispaiva', 'name' => 'Pääsiäispäivä', 'rule' => ['easter', 0]],
                    ['id' => 'toinen_paasiaispaiva', 'name' => '2. pääsiäispäivä', 'rule' => ['easter', 1]],
                    ['id' => 'vappu', 'name' => 'Vappu', 'rule' => ['fixed', '05-01']],
                    ['id' => 'helatorstai', 'name' => 'Helatorstai', 'rule' => ['easter', 39]],
                    ['id' => 'helluntaipaiva', 'name' => 'Helluntaipäivä', 'rule' => ['easter', 49]],
                    ['id' => 'juhannusaatto', 'name' => 'Juhannusaatto', 'rule' => ['weekday_on_or_after', '06-19', 'fri'], 'optional' => true],
                    ['id' => 'juhannuspaiva', 'name' => 'Juhannuspäivä', 'rule' => ['weekday_on_or_after', '06-20', 'sat']],
                    ['id' => 'pyhainpaiva', 'name' => 'Pyhäinpäivä', 'rule' => ['weekday_on_or_after', '10-31', 'sat']],
                    ['id' => 'itsenaisyyspaiva', 'name' => 'Itsenäisyyspäivä', 'rule' => ['fixed', '12-06']],
                    ['id' => 'jouluaatto', 'name' => 'Jouluaatto', 'rule' => ['fixed', '12-24'], 'optional' => true],
                    ['id' => 'joulupaiva', 'name' => 'Joulupäivä', 'rule' => ['fixed', '12-25']],
                    ['id' => 'tapaninpaiva', 'name' => 'Tapaninpäivä', 'rule' => ['fixed', '12-26']],
                ],
            ],
            'PL' => [
                'name' => 'Polska',
                'rules' => [
                    ['id' => 'nowy_rok', 'name' => 'Nowy Rok', 'rule' => ['fixed', '01-01']],
                    ['id' => 'trzech_kroli', 'name' => 'Święto Trzech Króli', 'rule' => ['fixed', '01-06'], 'from' => 2011],
                    ['id' => 'wielkanoc', 'name' => 'Wielkanoc', 'rule' => ['easter', 0]],
                    ['id' => 'poniedzialek_wielkanocny', 'name' => 'Poniedziałek Wielkanocny', 'rule' => ['easter', 1]],
                    ['id' => 'swieto_pracy', 'name' => 'Święto Pracy', 'rule' => ['fixed', '05-01']],
                    ['id' => 'konstytucja_3_maja', 'name' => 'Święto Konstytucji 3 Maja', 'rule' => ['fixed', '05-03']],
                    ['id' => 'zielone_swiatki', 'name' => 'Zielone Świątki', 'rule' => ['easter', 49]],
                    ['id' => 'boze_cialo', 'name' => 'Boże Ciało', 'rule' => ['easter', 60]],
                    ['id' => 'wniebowziecie', 'name' => 'Wniebowzięcie Najświętszej Maryi Panny', 'rule' => ['fixed', '08-15']],
                    ['id' => 'wszystkich_swietych', 'name' => 'Wszystkich Świętych', 'rule' => ['fixed', '11-01']],
                    ['id' => 'niepodleglosci', 'name' => 'Narodowe Święto Niepodległości', 'rule' => ['fixed', '11-11']],
                    // A public holiday from 2025.
                    ['id' => 'wigilia', 'name' => 'Wigilia Bożego Narodzenia', 'rule' => ['fixed', '12-24'], 'from' => 2025],
                    ['id' => 'boze_narodzenie_1', 'name' => 'Boże Narodzenie (pierwszy dzień)', 'rule' => ['fixed', '12-25']],
                    ['id' => 'boze_narodzenie_2', 'name' => 'Boże Narodzenie (drugi dzień)', 'rule' => ['fixed', '12-26']],
                ],
            ],
            'CA' => [
                'name' => 'Canada',
                'regions' => [
                    'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick',
                    'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories',
                    'NU' => 'Nunavut', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Québec',
                    'SK' => 'Saskatchewan', 'YT' => 'Yukon',
                ],
                'rules' => [
                    ['id' => 'new_year', 'name' => "New Year's Day / Jour de l'An", 'rule' => ['fixed', '01-01'], 'observed' => 'next_monday'],
                    ['id' => 'family_day', 'name' => 'Family Day', 'rule' => ['nth_weekday', 2, 'mon', 3], 'regions' => ['AB', 'BC', 'NB', 'ON', 'SK']],
                    ['id' => 'louis_riel_day', 'name' => 'Louis Riel Day', 'rule' => ['nth_weekday', 2, 'mon', 3], 'regions' => ['MB']],
                    ['id' => 'islander_day', 'name' => 'Islander Day', 'rule' => ['nth_weekday', 2, 'mon', 3], 'regions' => ['PE']],
                    ['id' => 'heritage_day_ns', 'name' => 'Heritage Day', 'rule' => ['nth_weekday', 2, 'mon', 3], 'regions' => ['NS']],
                    ['id' => 'good_friday', 'name' => 'Good Friday / Vendredi saint', 'rule' => ['easter', -2]],
                    ['id' => 'easter_monday', 'name' => 'Easter Monday / Lundi de Pâques', 'rule' => ['easter', 1], 'optional' => true],
                    // The Monday before 25 May (Journée nationale des patriotes in Québec).
                    ['id' => 'victoria_day', 'name' => 'Victoria Day / Fête de la Reine', 'rule' => ['weekday_on_or_before', '05-24', 'mon']],
                    ['id' => 'national_indigenous_peoples_day', 'name' => 'National Indigenous Peoples Day', 'rule' => ['fixed', '06-21'], 'regions' => ['NT', 'YT']],
                    ['id' => 'fete_nationale_quebec', 'name' => 'Fête nationale du Québec', 'rule' => ['fixed', '06-24'], 'regions' => ['QC']],
                    ['id' => 'canada_day', 'name' => 'Canada Day / Fête du Canada', 'rule' => ['fixed', '07-01'], 'observed' => 'next_monday'],
                    ['id' => 'nunavut_day', 'name' => 'Nunavut Day', 'rule' => ['fixed', '07-09'], 'regions' => ['NU']],
                    ['id' => 'civic_holiday', 'name' => 'Civic Holiday', 'rule' => ['nth_weekday', 8, 'mon', 1], 'regions' => ['AB', 'BC', 'NB', 'NT', 'NU', 'ON', 'SK']],
                    ['id' => 'discovery_day', 'name' => 'Discovery Day', 'rule' => ['nth_weekday', 8, 'mon', 3], 'regions' => ['YT']],
                    ['id' => 'labour_day', 'name' => 'Labour Day / Fête du Travail', 'rule' => ['nth_weekday', 9, 'mon', 1]],
                    ['id' => 'truth_and_reconciliation', 'name' => 'National Day for Truth and Reconciliation', 'rule' => ['fixed', '09-30'], 'from' => 2021],
                    ['id' => 'thanksgiving', 'name' => 'Thanksgiving / Action de grâce', 'rule' => ['nth_weekday', 10, 'mon', 2]],
                    ['id' => 'remembrance_day', 'name' => 'Remembrance Day / Jour du Souvenir', 'rule' => ['fixed', '11-11']],
                    ['id' => 'christmas', 'name' => 'Christmas Day / Noël', 'rule' => ['fixed', '12-25'], 'observed' => 'pair'],
                    ['id' => 'boxing_day', 'name' => 'Boxing Day / Lendemain de Noël', 'rule' => ['fixed', '12-26'], 'observed' => 'pair'],
                ],
            ],
            'AU' => [
                'name' => 'Australia',
                'regions' => [
                    'ACT' => 'Australian Capital Territory', 'NSW' => 'New South Wales', 'NT' => 'Northern Territory',
                    'QLD' => 'Queensland', 'SA' => 'South Australia', 'TAS' => 'Tasmania', 'VIC' => 'Victoria', 'WA' => 'Western Australia',
                ],
                'rules' => [
                    ['id' => 'new_year', 'name' => "New Year's Day", 'rule' => ['fixed', '01-01'], 'observed' => 'next_monday'],
                    ['id' => 'australia_day', 'name' => 'Australia Day', 'rule' => ['fixed', '01-26'], 'observed' => 'next_monday'],
                    ['id' => 'labour_day_wa', 'name' => 'Labour Day', 'rule' => ['nth_weekday', 3, 'mon', 1], 'regions' => ['WA']],
                    ['id' => 'labour_day_vic', 'name' => 'Labour Day', 'rule' => ['nth_weekday', 3, 'mon', 2], 'regions' => ['VIC']],
                    ['id' => 'eight_hours_day', 'name' => 'Eight Hours Day', 'rule' => ['nth_weekday', 3, 'mon', 2], 'regions' => ['TAS']],
                    ['id' => 'canberra_day', 'name' => 'Canberra Day', 'rule' => ['nth_weekday', 3, 'mon', 2], 'regions' => ['ACT']],
                    ['id' => 'adelaide_cup', 'name' => 'Adelaide Cup Day', 'rule' => ['nth_weekday', 3, 'mon', 2], 'regions' => ['SA']],
                    ['id' => 'good_friday', 'name' => 'Good Friday', 'rule' => ['easter', -2]],
                    ['id' => 'easter_saturday', 'name' => 'Easter Saturday', 'rule' => ['easter', -1], 'regions' => ['ACT', 'NSW', 'NT', 'QLD', 'SA', 'VIC']],
                    ['id' => 'easter_sunday', 'name' => 'Easter Sunday', 'rule' => ['easter', 0], 'regions' => ['ACT', 'NSW', 'QLD', 'VIC']],
                    ['id' => 'easter_monday', 'name' => 'Easter Monday', 'rule' => ['easter', 1]],
                    ['id' => 'anzac_day', 'name' => 'Anzac Day', 'rule' => ['fixed', '04-25']],
                    ['id' => 'labour_day_qld', 'name' => 'Labour Day', 'rule' => ['nth_weekday', 5, 'mon', 1], 'regions' => ['QLD']],
                    ['id' => 'may_day_nt', 'name' => 'May Day', 'rule' => ['nth_weekday', 5, 'mon', 1], 'regions' => ['NT']],
                    ['id' => 'reconciliation_day', 'name' => 'Reconciliation Day', 'rule' => ['weekday_on_or_after', '05-27', 'mon'], 'regions' => ['ACT'], 'from' => 2018],
                    ['id' => 'western_australia_day', 'name' => 'Western Australia Day', 'rule' => ['nth_weekday', 6, 'mon', 1], 'regions' => ['WA']],
                    ['id' => 'kings_birthday', 'name' => "King's Birthday", 'rule' => ['nth_weekday', 6, 'mon', 2], 'regions' => ['ACT', 'NSW', 'NT', 'SA', 'TAS', 'VIC']],
                    ['id' => 'picnic_day', 'name' => 'Picnic Day', 'rule' => ['nth_weekday', 8, 'mon', 1], 'regions' => ['NT']],
                    ['id' => 'labour_day_october', 'name' => 'Labour Day', 'rule' => ['nth_weekday', 10, 'mon', 1], 'regions' => ['ACT', 'NSW', 'SA']],
                    ['id' => 'kings_birthday_qld', 'name' => "King's Birthday", 'rule' => ['nth_weekday', 10, 'mon', 1], 'regions' => ['QLD']],
                    ['id' => 'melbourne_cup', 'name' => 'Melbourne Cup Day', 'rule' => ['nth_weekday', 11, 'tue', 1], 'regions' => ['VIC'], 'optional' => true],
                    ['id' => 'christmas', 'name' => 'Christmas Day', 'rule' => ['fixed', '12-25'], 'observed' => 'pair'],
                    ['id' => 'boxing_day', 'name' => 'Boxing Day', 'rule' => ['fixed', '12-26'], 'observed' => 'pair'],
                ],
            ],
            'BR' => [
                'name' => 'Brasil',
                'rules' => [
                    ['id' => 'confraternizacao', 'name' => 'Confraternização Universal', 'rule' => ['fixed', '01-01']],
                    // Ponto facultativo: optional days, picked by the library.
                    ['id' => 'carnaval_segunda', 'name' => 'Carnaval (segunda-feira)', 'rule' => ['easter', -48], 'optional' => true],
                    ['id' => 'carnaval_terca', 'name' => 'Carnaval (terça-feira)', 'rule' => ['easter', -47], 'optional' => true],
                    ['id' => 'sexta_feira_santa', 'name' => 'Sexta-feira Santa', 'rule' => ['easter', -2]],
                    ['id' => 'tiradentes', 'name' => 'Tiradentes', 'rule' => ['fixed', '04-21']],
                    ['id' => 'dia_do_trabalho', 'name' => 'Dia do Trabalho', 'rule' => ['fixed', '05-01']],
                    ['id' => 'corpus_christi', 'name' => 'Corpus Christi', 'rule' => ['easter', 60], 'optional' => true],
                    ['id' => 'independencia', 'name' => 'Independência do Brasil', 'rule' => ['fixed', '09-07']],
                    ['id' => 'nossa_senhora_aparecida', 'name' => 'Nossa Senhora Aparecida', 'rule' => ['fixed', '10-12']],
                    ['id' => 'finados', 'name' => 'Finados', 'rule' => ['fixed', '11-02']],
                    ['id' => 'proclamacao_da_republica', 'name' => 'Proclamação da República', 'rule' => ['fixed', '11-15']],
                    ['id' => 'consciencia_negra', 'name' => 'Dia Nacional de Zumbi e da Consciência Negra', 'rule' => ['fixed', '11-20'], 'from' => 2024],
                    ['id' => 'natal', 'name' => 'Natal', 'rule' => ['fixed', '12-25']],
                ],
            ],
            'AR' => [
                'name' => 'Argentina',
                'rules' => [
                    ['id' => 'ano_nuevo', 'name' => 'Año Nuevo', 'rule' => ['fixed', '01-01']],
                    ['id' => 'carnaval_lunes', 'name' => 'Carnaval (lunes)', 'rule' => ['easter', -48]],
                    ['id' => 'carnaval_martes', 'name' => 'Carnaval (martes)', 'rule' => ['easter', -47]],
                    ['id' => 'memoria', 'name' => 'Día Nacional de la Memoria por la Verdad y la Justicia', 'rule' => ['fixed', '03-24']],
                    ['id' => 'malvinas', 'name' => 'Día del Veterano y de los Caídos en la Guerra de Malvinas', 'rule' => ['fixed', '04-02']],
                    ['id' => 'jueves_santo', 'name' => 'Jueves Santo', 'rule' => ['easter', -3], 'optional' => true],
                    ['id' => 'viernes_santo', 'name' => 'Viernes Santo', 'rule' => ['easter', -2]],
                    ['id' => 'dia_del_trabajador', 'name' => 'Día del Trabajador', 'rule' => ['fixed', '05-01']],
                    ['id' => 'revolucion_de_mayo', 'name' => 'Día de la Revolución de Mayo', 'rule' => ['fixed', '05-25']],
                    // Trasladables (law 27.399): Tuesday/Wednesday to the Monday
                    // before, Thursday/Friday to the Monday after.
                    ['id' => 'guemes', 'name' => 'Paso a la Inmortalidad del General Martín Miguel de Güemes', 'rule' => ['fixed', '06-17'], 'observed' => 'ar_movable'],
                    ['id' => 'belgrano', 'name' => 'Paso a la Inmortalidad del General Manuel Belgrano', 'rule' => ['fixed', '06-20']],
                    ['id' => 'independencia', 'name' => 'Día de la Independencia', 'rule' => ['fixed', '07-09']],
                    ['id' => 'san_martin', 'name' => 'Paso a la Inmortalidad del General José de San Martín', 'rule' => ['fixed', '08-17'], 'observed' => 'ar_movable'],
                    ['id' => 'diversidad_cultural', 'name' => 'Día del Respeto a la Diversidad Cultural', 'rule' => ['fixed', '10-12'], 'observed' => 'ar_movable'],
                    ['id' => 'soberania_nacional', 'name' => 'Día de la Soberanía Nacional', 'rule' => ['fixed', '11-20'], 'observed' => 'ar_movable'],
                    ['id' => 'inmaculada', 'name' => 'Inmaculada Concepción de María', 'rule' => ['fixed', '12-08']],
                    ['id' => 'navidad', 'name' => 'Navidad', 'rule' => ['fixed', '12-25']],
                ],
            ],
            'MX' => [
                'name' => 'México',
                'rules' => [
                    ['id' => 'ano_nuevo', 'name' => 'Año Nuevo', 'rule' => ['fixed', '01-01']],
                    ['id' => 'dia_de_la_constitucion', 'name' => 'Día de la Constitución', 'rule' => ['nth_weekday', 2, 'mon', 1]],
                    ['id' => 'natalicio_benito_juarez', 'name' => 'Natalicio de Benito Juárez', 'rule' => ['nth_weekday', 3, 'mon', 3]],
                    ['id' => 'jueves_santo', 'name' => 'Jueves Santo', 'rule' => ['easter', -3], 'optional' => true],
                    ['id' => 'viernes_santo', 'name' => 'Viernes Santo', 'rule' => ['easter', -2], 'optional' => true],
                    ['id' => 'dia_del_trabajo', 'name' => 'Día del Trabajo', 'rule' => ['fixed', '05-01']],
                    ['id' => 'dia_de_la_independencia', 'name' => 'Día de la Independencia', 'rule' => ['fixed', '09-16']],
                    ['id' => 'dia_de_la_revolucion', 'name' => 'Día de la Revolución', 'rule' => ['nth_weekday', 11, 'mon', 3]],
                    ['id' => 'navidad', 'name' => 'Navidad', 'rule' => ['fixed', '12-25']],
                ],
            ],
        ];
    }
}
