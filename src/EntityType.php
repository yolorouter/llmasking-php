<?php

// src/EntityType.php

namespace Yolorouter\Llmasking;

/** Built-in entity types. String constants (not enum) so WithEntityType can register runtime types. */
final class EntityType
{
    public const PHONE = 'PHONE';
    public const IDCARD = 'IDCARD';
    public const LANDLINE = 'LANDLINE';
    public const SSN = 'SSN';
    public const EMAIL = 'EMAIL';
    public const BANKCARD = 'BANKCARD';
    public const IP = 'IP';
    public const URL = 'URL';
    public const KEYWORD = 'KEYWORD';
    public const CLOUDKEY = 'CLOUDKEY';
    public const PRIVATEKEY = 'PRIVATEKEY';
    public const JWT = 'JWT';
    public const GITTOKEN = 'GITTOKEN';
    public const SECRET = 'SECRET';
}
