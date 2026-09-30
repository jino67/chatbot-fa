<?php

namespace App\Channels\WhatsApp;

/**
 * Port sortant vers WhatsApp. Le reste de l'application ne connait ni Meta ni Twilio :
 * changer de fournisseur pour un client revient a changer le type de son Channel.
 */
interface WhatsAppGateway
{
    /**
     * Envoie un texte libre (valable dans la fenetre de service de 24 h). Jusqu'a trois reponses rapides
     * peuvent l'accompagner : Meta les affiche comme boutons, Twilio les ignore.
     *
     * @param  list<string>  $buttons
     * @return string identifiant du message chez le fournisseur
     *
     * @throws GatewayException
     */
    public function sendText(string $to, string $text, array $buttons = []): string;

    /** Accuse de lecture (les deux coches bleues) : sans effet si le fournisseur ne le supporte pas. */
    public function markRead(string $messageId): void;

    /**
     * Test de connexion utilise par le back-office avant d'activer un canal.
     *
     * @return array{ok:bool, detail:string}
     */
    public function checkConnection(): array;

    // ---- Modeles de messages (obligatoires pour ecrire a un client hors de la fenetre de 24 h) ----

    /**
     * Modeles connus du fournisseur, avec leur statut d'approbation.
     *
     * @return list<array{external_id:?string, name:string, language:string, category:string, status:string, components:array, body:string, variables_count:int, rejected_reason:?string}>
     *
     * @throws GatewayException
     */
    public function listTemplates(): array;

    /** Le fournisseur permet-il de creer des modeles depuis l'application ? (Twilio : dans sa console.) */
    public function supportsTemplateCreation(): bool;

    /**
     * Soumet un nouveau modele a approbation.
     *
     * @param  array{name:string, language:string, category:string, body:string, body_examples:list<string>, header:?string, footer:?string, buttons:list<array<string,string>>}  $definition
     * @return array{external_id:?string, status:string}
     *
     * @throws GatewayException
     */
    public function createTemplate(array $definition): array;

    /** @throws GatewayException */
    public function deleteTemplate(string $name, ?string $externalId = null): void;

    /**
     * Envoie un modele approuve avec ses variables.
     *
     * @param  list<string>  $variables
     * @return string identifiant du message chez le fournisseur
     *
     * @throws GatewayException
     */
    public function sendTemplate(string $to, string $name, string $language, array $variables, ?string $externalId = null): string;
}
