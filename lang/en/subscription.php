<?php

return [
    'days' => '{1} 1 day|[2,*] :count days',
    'mail' => [
        'greeting' => 'Hello :name',
        'subject' => [
            'trial' => 'E-Zaki ERP: your trial period is ending',
            'test' => 'E-Zaki ERP: your test period is ending',
            'paid' => 'E-Zaki ERP: your subscription is ending',
        ],
        'ends' => [
            'trial' => 'Your company trial period ends in :days (on :date).',
            'test' => 'Your company test period ends in :days (on :date).',
            'paid' => 'Your company subscription ends in :days (on :date).',
        ],
        'today' => [
            'trial' => 'Your company trial period ends in less than one day (on :date).',
            'test' => 'Your company test period ends in less than one day (on :date).',
            'paid' => 'Your company subscription ends in less than one day (on :date).',
        ],
        'generic' => 'Your company subscription is scheduled to end on :date.',
        'action' => 'Please contact your platform administrator to arrange renewal.',
    ],
];
