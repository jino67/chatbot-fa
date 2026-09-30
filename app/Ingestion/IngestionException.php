<?php

namespace App\Ingestion;

use RuntimeException;

/** Erreur "metier" d'ingestion, dont le message est affichable tel quel au client. */
class IngestionException extends RuntimeException {}
