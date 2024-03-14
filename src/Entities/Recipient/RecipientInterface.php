<?php

namespace EuroSms\Entities\Recipient;

interface RecipientInterface
{
    const string RECIPIENT_DEFAULT_COUNTRY = 'SK';
    const int ERROR_WRONG_NUMBER = 0;
    const int ERROR_RECIPIENT_NOT_DEFINED = 1;
    const int ERROR_RECIPIENT_COLLECTION_NOT_DEFINED = 2;
}
