<?php

/**
 * FAQ content for the split and delegation landings.
 *
 * One array per landing, transcribed verbatim from the design mockup. This is
 * the single source rendered as <details> markup (render_faq()) and mirrored
 * into the FAQPage JSON-LD (faq_schema()) -- never maintained twice.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

/**
 * @return array<int, array{question: string, answer: string}>
 */
function faq_items(string $template): array
{
    if ($template === TEMPLATE_SPLIT) {
        return [
            ['question' => 'Qu’est-ce que le split de paiement pour une conciergerie ?', 'answer' => 'Le split de paiement répartit automatiquement un encaissement entre plusieurs bénéficiaires. Avec Unlocker, les règles de votre conciergerie déterminent la part du propriétaire, votre commission et le paiement des prestataires, à partir des fonds reçus pour le séjour.'],
            ['question' => 'Comment payer les prestataires sans avancer les frais de ménage ?', 'answer' => 'Prévoyez les prestations dans les règles de répartition du logement. Leur montant est affecté aux prestataires depuis les fonds du séjour encaissés, sans attendre un règlement manuel du propriétaire. Le split ne finance pas le ménage avant la réception de ces fonds.'],
            ['question' => 'Peut-on répartir les paiements Airbnb et Booking ?', 'answer' => 'Oui, les paiements des plateformes comme Airbnb ou Booking peuvent être versés sur l’IBAN dédié au logement. Unlocker répartit ensuite les fonds reçus selon les règles définies. Les coordonnées de versement se renseignent auprès de chaque plateforme.'],
            ['question' => 'Le split de paiement supprime-t-il tous les délais de règlement ?', 'answer' => 'Non. Le split réduit les attentes liées aux reversements manuels des propriétaires, mais la répartition dépend de la réception des fonds. Les délais de versement des plateformes et de traitement bancaire restent applicables : le service ne garantit pas un paiement instantané.'],
            ['question' => 'Que voit le propriétaire sur ses encaissements ?', 'answer' => 'Le propriétaire consulte les encaissements et leur répartition dans son espace dédié. Il peut suivre la part qui lui revient et les prestations prises en compte. Cette visibilité facilite vos échanges sur les revenus du logement et les services réalisés.'],
            ['question' => 'Quels frais prévoir pour utiliser le split Unlocker ?', 'answer' => 'Les frais dépendent de l’offre retenue par votre conciergerie. Consultez ses conditions ou demandez une présentation à l’équipe avant de démarrer. Les montants affichés dans l’exemple décrivent la répartition d’un séjour ; ils ne constituent pas un tarif Unlocker.'],
            ['question' => 'Le split de paiement inclut-il la délégation Carte G ?', 'answer' => 'Le split de paiement et la délégation Carte G répondent à des besoins distincts : répartir les encaissements et définir des missions déléguées. Consultez votre offre pour connaître les services inclus et le périmètre du contrat de délégation.'],
        ];
    }

    if ($template === TEMPLATE_DELEGATION) {
        return [
            ['question' => 'Qu’est-ce que la délégation Carte G pour une conciergerie ?', 'answer' => 'La délégation Carte G Unlocker définit les missions confiées pour l’activité de location courte durée de votre conciergerie. Son périmètre et les responsabilités sont fixés par contrat, avec un adossement à la carte G Unlocker. Unlocker reste titulaire de sa carte professionnelle.'],
            ['question' => 'Une conciergerie de location courte durée doit-elle avoir une carte G ?', 'answer' => 'Cela dépend des missions réellement exercées, et non du nom de l’activité. En France, la loi Hoguet encadre notamment la gestion immobilière pour le compte d’autrui. Les prestations d’accueil ou de ménage ne suffisent pas, à elles seules, à déterminer cette obligation. <a href="https://www.legifrance.gouv.fr/loda/id/LEGITEXT000006068387/">Consulter le cadre de la loi Hoguet</a>.'],
            ['question' => 'Quelles missions sont prévues dans la délégation Carte G ?', 'answer' => 'Le contrat de délégation précise les missions couvertes et les responsabilités de chacun. Votre conciergerie conserve les prestations sur le terrain et la relation propriétaire. Unlocker apporte le cadre des missions confiées, avec les documents de gestion et les flux centralisés pour leur suivi.'],
            ['question' => 'Puis-je garder mes propriétaires et mes tarifs de conciergerie ?', 'answer' => 'Oui. Vous gardez la relation avec vos propriétaires et définissez vos tarifs pour les prestations opérationnelles dans votre contrat de prestation. Cette rémunération de conciergerie est distincte des frais de l’offre Unlocker ; les conditions respectives sont à examiner avant de vous engager.'],
            ['question' => 'Le split de paiement remplace-t-il la délégation Carte G ?', 'answer' => 'Non. Le split répartit les fonds reçus entre le propriétaire, votre commission et les prestataires. La délégation Carte G définit les missions confiées et leurs responsabilités. Ces fonctions sont complémentaires, avec des conditions propres à chaque offre. <a ' . 'href="' . esc_url(split_url()) . '"' . '>Comprendre le split de paiement pour une conciergerie</a>.'],
            ['question' => 'Comment démarrer une délégation Carte G en ligne ?', 'answer' => 'Créez votre compte Unlocker, choisissez votre offre et complétez le dossier de votre conciergerie. L’équipe examine votre activité et les pièces transmises. Après validation, vous signez la délégation pour formaliser les missions et responsabilités prévues au contrat.'],
            ['question' => 'Quels frais prévoir pour la délégation Carte G Unlocker ?', 'answer' => 'Les frais dépendent de l’offre choisie et des services retenus pour votre conciergerie. Consultez les conditions applicables avant de signer votre délégation, et échangez avec l’équipe si un point doit être précisé. <a href="https://unlocker.io/carte-g/">Consulter l’offre Carte G Unlocker</a>.'],
            ['question' => 'La délégation Carte G couvre-t-elle une conciergerie hors de France ?', 'answer' => 'La loi Hoguet constitue un cadre français. Pour une conciergerie située hors de France, le pays et l’activité doivent être examinés avec Unlocker avant de confirmer les possibilités de collaboration. L’appartenance à la zone SEPA ne suffit pas à établir cette couverture.'],
            ['question' => 'Puis-je réserver une démo avant de démarrer ?', 'answer' => 'Oui. Le bouton « Réserver une démo » vous conduit au parcours de présentation de votre conciergerie, puis à la prise de rendez-vous. Vous pourrez échanger sur vos logements, votre organisation et les missions envisagées avant de choisir votre offre.'],
        ];
    }

    return [];
}

function render_faq(string $template): void
{
    foreach (faq_items($template) as $item) {
        echo '<details><summary>' . esc_html($item['question']) . '</summary><p>' . $item['answer'] . '</p></details>';
    }
}

/**
 * @return array<string, mixed>|null
 */
function faq_schema(string $template): ?array
{
    $items = faq_items($template);

    if ($items === []) {
        return null;
    }

    return [
        '@type' => 'FAQPage',
        'mainEntity' => array_map(
            static function (array $item): array {
                return [
                    '@type' => 'Question',
                    'name' => wp_strip_all_tags($item['question']),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => wp_strip_all_tags($item['answer']),
                    ],
                ];
            },
            $items
        ),
    ];
}
