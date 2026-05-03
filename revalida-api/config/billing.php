<?php

return [
    'provider' => 'mockpay',
    'currency' => 'BRL',
    'plans' => [
        [
            'code' => 'free',
            'name' => 'Grátis',
            'category' => 'revalida',
            'price_cents' => 0,
            'monthly_question_limit' => 1000,
            'description' => 'Para testar a plataforma.',
        ],
        [
            'code' => 'pro',
            'name' => 'Pro',
            'category' => 'revalida',
            'price_cents' => 2900,
            'monthly_question_limit' => 5000,
            'description' => 'Banco completo e ritmo forte de estudo.',
        ],
        [
            'code' => 'mentoria',
            'name' => 'Mentoria',
            'category' => 'revalida',
            'price_cents' => 9900,
            'monthly_question_limit' => 10000,
            'description' => 'Pro + acompanhamento individual.',
        ],
        [
            'code' => 'free_geral',
            'name' => 'Grátis (Estudo geral)',
            'category' => 'estudo_geral',
            'price_cents' => 0,
            'monthly_question_limit' => 1000,
            'description' => 'Para testar a plataforma (conteúdo geral).',
        ],
        [
            'code' => 'pro_geral',
            'name' => 'Pro (Estudo geral)',
            'category' => 'estudo_geral',
            'price_cents' => 2900,
            'monthly_question_limit' => 5000,
            'description' => 'Banco completo para estudo geral.',
        ],
        [
            'code' => 'mentoria_geral',
            'name' => 'Mentoria (Estudo geral)',
            'category' => 'estudo_geral',
            'price_cents' => 9900,
            'monthly_question_limit' => 10000,
            'description' => 'Pro + acompanhamento individual (conteúdo geral).',
        ],
    ],
];
