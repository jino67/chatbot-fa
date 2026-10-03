<?php

namespace App\Social\Auth;

use RuntimeException;

/** Une connexion externe qui échoue. Le message est celui que la personne lit : en français, sans détail technique. */
class SocialAuthException extends RuntimeException {}
