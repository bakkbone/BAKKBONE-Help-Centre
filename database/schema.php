<?php
// Column spec "type[:length][|default]": a default makes it NOT NULL ("|" alone = empty string); otherwise nullable.
return [
    'settings' => [
        'columns' => ['name' => 'string:100|', 'value' => 'longtext'],
        'primary' => ['name'],
    ],

    'departments' => [
        'columns' => [
            'id' => 'pk', 'name' => 'string:120|', 'email_account_id' => 'int', 'manager_id' => 'int',
            'sla_id' => 'int', 'signature' => 'text', 'public' => 'bool|1', 'autoresp_enabled' => 'bool|1',
            'alert_members' => 'bool|1', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
    ],

    'teams' => [
        'columns' => ['id' => 'pk', 'name' => 'string:120|', 'lead_id' => 'int', 'active' => 'bool|1', 'alert_members' => 'bool|1', 'created_at' => 'datetime'],
    ],
    'team_members' => [
        'columns' => ['team_id' => 'int|0', 'staff_id' => 'int|0'],
        'primary' => ['team_id', 'staff_id'],
    ],

    'roles' => [
        'columns' => ['id' => 'pk', 'name' => 'string:120|', 'permissions' => 'text', 'created_at' => 'datetime'],
    ],

    'staff' => [
        'columns' => [
            'id' => 'pk', 'username' => 'string:64|', 'email' => 'string:190|', 'first_name' => 'string:80|',
            'last_name' => 'string:80|', 'phone' => 'string:40', 'password_hash' => 'string:255',
            'dept_id' => 'int', 'role_id' => 'int', 'is_admin' => 'bool|0', 'active' => 'bool|1',
            'onvacation' => 'bool|0', 'signature' => 'text', 'totp_secret' => 'string:255',
            'totp_enabled' => 'bool|0', 'totp_last_counter' => 'bigint', 'reply_redirect' => 'string:20|ticket', 'default_signature' => 'string:10',
            'last_login_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
        'unique' => [['username'], ['email']],
    ],
    'staff_dept_access' => [
        'columns' => ['staff_id' => 'int|0', 'dept_id' => 'int|0'],
        'primary' => ['staff_id', 'dept_id'],
    ],

    'organizations' => [
        'columns' => [
            'id' => 'pk', 'name' => 'string:190|', 'domains' => 'string:255', 'sharing' => 'bool|0',
            'phone' => 'string:40', 'website' => 'string:255', 'notes' => 'text', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
    ],

    'users' => [
        'columns' => [
            'id' => 'pk', 'name' => 'string:190|', 'email' => 'string:190|', 'phone' => 'string:40',
            'org_id' => 'int', 'password_hash' => 'string:255', 'status' => 'string:20|active',
            'verified' => 'bool|0', 'notes' => 'text', 'last_login_at' => 'datetime',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
        'unique' => [['email']],
        'index' => [['org_id']],
    ],

    'sla' => [
        'columns' => ['id' => 'pk', 'name' => 'string:120|', 'grace_hours' => 'int|24', 'active' => 'bool|1', 'disable_overdue_alerts' => 'bool|0', 'notes' => 'text', 'created_at' => 'datetime'],
    ],

    'priorities' => [
        'columns' => ['id' => 'pk', 'name' => 'string:60|', 'color' => 'string:20|#64748b', 'urgency' => 'int|0', 'public' => 'bool|1'],
    ],

    'ticket_statuses' => [
        'columns' => ['id' => 'pk', 'name' => 'string:60|', 'state' => 'string:20|open', 'client_name' => 'string:60', 'sort' => 'int|0'],
    ],

    'help_topics' => [
        'columns' => [
            'id' => 'pk', 'name' => 'string:120|', 'parent_id' => 'int', 'dept_id' => 'int', 'priority_id' => 'int',
            'sla_id' => 'int', 'staff_id' => 'int', 'team_id' => 'int', 'status_id' => 'int', 'public' => 'bool|1',
            'active' => 'bool|1', 'disable_autoresponse' => 'bool|0', 'sort' => 'int|0', 'notes' => 'text', 'created_at' => 'datetime',
        ],
    ],

    'custom_fields' => [
        'columns' => [
            'id' => 'pk', 'name' => 'string:64|', 'label' => 'string:190|', 'type' => 'string:20|text',
            'options' => 'text', 'hint' => 'string:255', 'required' => 'bool|0', 'client_visible' => 'bool|1',
            'topic_ids' => 'string:255', 'sort' => 'int|0', 'active' => 'bool|1',
        ],
        'unique' => [['name']],
    ],

    'tickets' => [
        'columns' => [
            'id' => 'pk', 'number' => 'string:32|', 'user_id' => 'int|0', 'dept_id' => 'int', 'topic_id' => 'int',
            'priority_id' => 'int', 'status_id' => 'int', 'sla_id' => 'int', 'staff_id' => 'int', 'team_id' => 'int',
            'subject' => 'string:255|', 'source' => 'string:20|web', 'ip' => 'string:45', 'email_account_id' => 'int',
            'is_overdue' => 'bool|0', 'is_answered' => 'bool|0', 'due_at' => 'datetime', 'closed_at' => 'datetime',
            'last_message_at' => 'datetime', 'last_response_at' => 'datetime', 'merged_into' => 'int',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
        'unique' => [['number']],
        'index' => [['user_id'], ['status_id'], ['dept_id'], ['staff_id'], ['created_at'], ['due_at']],
    ],

    'thread_entries' => [
        'columns' => [
            'id' => 'pk', 'ticket_id' => 'int|0', 'type' => 'string:1|M', 'user_id' => 'int', 'staff_id' => 'int',
            'poster' => 'string:190', 'body' => 'longtext', 'format' => 'string:10|html', 'source' => 'string:20',
            'message_id' => 'string:255', 'recipients' => 'text', 'ip' => 'string:45', 'created_at' => 'datetime',
        ],
        'index' => [['ticket_id'], ['message_id']],
    ],

    'attachments' => [
        'columns' => [
            'id' => 'pk', 'ticket_id' => 'int', 'entry_id' => 'int', 'task_id' => 'int', 'filename' => 'string:255|',
            'mime' => 'string:120|application/octet-stream', 'size' => 'int|0', 'path' => 'string:255|', 'sha256' => 'string:64',
            'content_id' => 'string:255', 'inline' => 'bool|0', 'created_at' => 'datetime',
        ],
        'index' => [['ticket_id'], ['entry_id']],
    ],

    'ticket_events' => [
        'columns' => ['id' => 'pk', 'ticket_id' => 'int|0', 'staff_id' => 'int', 'user_id' => 'int', 'event' => 'string:40|', 'data' => 'text', 'created_at' => 'datetime'],
        'index' => [['ticket_id']],
    ],

    'ticket_collaborators' => [
        'columns' => ['id' => 'pk', 'ticket_id' => 'int|0', 'user_id' => 'int|0', 'active' => 'bool|1', 'created_at' => 'datetime'],
        'unique' => [['ticket_id', 'user_id']],
    ],

    'ticket_field_values' => [
        'columns' => ['ticket_id' => 'int|0', 'field_id' => 'int|0', 'value' => 'text'],
        'primary' => ['ticket_id', 'field_id'],
    ],

    'ticket_locks' => [
        'columns' => ['ticket_id' => 'int|0', 'staff_id' => 'int|0', 'expires_at' => 'datetime'],
        'primary' => ['ticket_id'],
    ],

    'canned_responses' => [
        'columns' => ['id' => 'pk', 'title' => 'string:190|', 'body' => 'longtext', 'dept_id' => 'int', 'active' => 'bool|1', 'created_at' => 'datetime', 'updated_at' => 'datetime'],
    ],

    'filters' => [
        'columns' => [
            'id' => 'pk', 'name' => 'string:120|', 'active' => 'bool|1', 'sort' => 'int|0', 'match_all' => 'bool|1',
            'stop_on_match' => 'bool|0', 'sources' => 'string:120', 'rules' => 'text', 'actions' => 'text',
            'notes' => 'text', 'created_at' => 'datetime',
        ],
    ],

    'email_accounts' => [
        'columns' => [
            'id' => 'pk', 'name' => 'string:120|', 'email' => 'string:190|', 'from_name' => 'string:190',
            'auth_type' => 'string:20|password',
            'ms_tenant' => 'string:120', 'ms_client_id' => 'string:120', 'ms_client_secret' => 'text',
            'inbound_protocol' => 'string:10|none', 'in_host' => 'string:190', 'in_port' => 'int',
            'in_encryption' => 'string:10|ssl', 'in_username' => 'string:190', 'in_password' => 'text',
            'in_folder' => 'string:120|INBOX', 'in_post_action' => 'string:10|seen', 'in_archive_folder' => 'string:120',
            'outbound_protocol' => 'string:10|mail', 'smtp_host' => 'string:190', 'smtp_port' => 'int',
            'smtp_encryption' => 'string:10|tls', 'smtp_auth' => 'bool|1', 'smtp_username' => 'string:190', 'smtp_password' => 'text',
            'dept_id' => 'int', 'priority_id' => 'int', 'topic_id' => 'int', 'active' => 'bool|1',
            'last_fetch_at' => 'datetime', 'last_error' => 'text', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
    ],

    'mail_seen' => [
        'columns' => ['account_id' => 'int|0', 'uid' => 'string:190|', 'created_at' => 'datetime'],
        'primary' => ['account_id', 'uid'],
    ],

    'mail_queue' => [
        'columns' => [
            'id' => 'pk', 'account_id' => 'int', 'payload' => 'longtext', 'attempts' => 'int|0', 'last_error' => 'text',
            'next_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'failed' => 'bool|0', 'created_at' => 'datetime',
        ],
        'index' => [['sent_at', 'next_attempt_at']],
    ],

    'email_templates' => [
        'columns' => ['id' => 'pk', 'code' => 'string:60|', 'subject' => 'string:255|', 'body' => 'longtext', 'updated_at' => 'datetime'],
        'unique' => [['code']],
    ],

    'kb_categories' => [
        'columns' => ['id' => 'pk', 'name' => 'string:190|', 'description' => 'text', 'public' => 'bool|1', 'sort' => 'int|0', 'created_at' => 'datetime'],
    ],
    'kb_articles' => [
        'columns' => [
            'id' => 'pk', 'category_id' => 'int|0', 'question' => 'string:255|', 'answer' => 'longtext', 'keywords' => 'string:255',
            'public' => 'bool|1', 'views' => 'int|0', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
        'index' => [['category_id']],
    ],

    'tasks' => [
        'columns' => [
            'id' => 'pk', 'ticket_id' => 'int', 'title' => 'string:255|', 'description' => 'longtext', 'dept_id' => 'int',
            'staff_id' => 'int', 'status' => 'string:10|open', 'due_at' => 'datetime', 'created_by' => 'int',
            'created_at' => 'datetime', 'closed_at' => 'datetime',
        ],
        'index' => [['ticket_id'], ['staff_id']],
    ],

    'webhooks' => [
        'columns' => [
            'id' => 'pk', 'name' => 'string:120|', 'url' => 'string:500|', 'method' => 'string:10|POST',
            'content_type' => 'string:20|json', 'body_template' => 'longtext', 'headers' => 'text', 'secret' => 'text',
            'events' => 'string:255|ticket.created', 'dept_ids' => 'string:255', 'active' => 'bool|1',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
    ],
    'webhook_deliveries' => [
        'columns' => [
            'id' => 'pk', 'webhook_id' => 'int|0', 'event' => 'string:40|', 'ticket_id' => 'int', 'request_body' => 'longtext',
            'request_headers' => 'text', 'status_code' => 'int', 'response_body' => 'text', 'attempts' => 'int|0',
            'success' => 'bool|0', 'next_attempt_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
        'index' => [['webhook_id'], ['success', 'next_attempt_at']],
    ],

    'api_keys' => [
        'columns' => [
            'id' => 'pk', 'name' => 'string:120|', 'key_hash' => 'string:64|', 'key_prefix' => 'string:12|',
            'ip_allow' => 'text', 'can_create_tickets' => 'bool|1', 'can_pipe' => 'bool|0', 'active' => 'bool|1',
            'last_used_at' => 'datetime', 'created_at' => 'datetime',
        ],
        'unique' => [['key_hash']],
    ],

    'banlist' => [
        'columns' => ['id' => 'pk', 'pattern' => 'string:190|', 'notes' => 'string:255', 'created_at' => 'datetime'],
        'unique' => [['pattern']],
    ],

    'otp_codes' => [
        'columns' => [
            'id' => 'pk', 'actor' => 'string:10|', 'actor_id' => 'int|0', 'purpose' => 'string:20|', 'code_hash' => 'string:64|',
            'attempts' => 'int|0', 'expires_at' => 'datetime', 'used_at' => 'datetime', 'ip' => 'string:45', 'created_at' => 'datetime',
        ],
        'index' => [['actor', 'actor_id', 'purpose']],
    ],

    'login_attempts' => [
        'columns' => ['id' => 'pk', 'scope' => 'string:20|', 'identifier' => 'string:190|', 'ip' => 'string:45', 'success' => 'bool|0', 'created_at' => 'datetime'],
        'index' => [['scope', 'identifier', 'created_at'], ['ip', 'created_at']],
    ],

    'saved_searches' => [
        'columns' => ['id' => 'pk', 'staff_id' => 'int|0', 'name' => 'string:120|', 'query' => 'text', 'created_at' => 'datetime'],
        'index' => [['staff_id']],
    ],

    'system_log' => [
        'columns' => ['id' => 'pk', 'level' => 'string:10|info', 'title' => 'string:255|', 'message' => 'longtext', 'created_at' => 'datetime'],
        'index' => [['created_at']],
    ],

    'import_map' => [
        'columns' => ['source' => 'string:20|', 'type' => 'string:20|', 'old_id' => 'int|0', 'new_id' => 'int|0'],
        'primary' => ['source', 'type', 'old_id'],
    ],

    'audit_log' => [
        'columns' => [
            'id' => 'pk', 'staff_id' => 'int', 'user_id' => 'int', 'action' => 'string:60|', 'object_type' => 'string:40',
            'object_id' => 'int', 'details' => 'text', 'ip' => 'string:45', 'created_at' => 'datetime',
        ],
        'index' => [['created_at'], ['object_type', 'object_id']],
    ],
];
