import psycopg2
import uuid
import json
import time

MASTER_TENANT_ID = "0193a5b0-7000-7000-8000-000000000001"
CUSTOMER_EMAIL = "honghoang.striver@gmail.com"
CUSTOMER_NAME = "Hong Hoang"
TENANT_SLUG = "honghoang"
TENANT_NAME = "Gia Đình Hong Hoang"

conn = psycopg2.connect(
    dbname="aicenty_butler_db",
    user="aicenty_user",
    password="AicentySuperSecureDbPass2026!",
    host="127.0.0.1",
    port=5435
)
cur = conn.cursor()

try:
    # 1. Create or get tenant
    cur.execute("SELECT id, name, slug FROM tenants WHERE slug = %s;", (TENANT_SLUG,))
    row = cur.fetchone()
    if row:
        new_tenant_id = str(row[0])
        print(f"Tenant {TENANT_SLUG} already exists with ID: {new_tenant_id}")
    else:
        cur.execute("SELECT uuid_generate_v7();")
        new_tenant_id = str(cur.fetchone()[0])
        cur.execute("""
            INSERT INTO tenants (id, name, slug, status, settings, created_at, updated_at)
            VALUES (%s, %s, %s, 'active', '{}'::jsonb, now(), now())
            RETURNING id;
        """, (new_tenant_id, TENANT_NAME, TENANT_SLUG))
        print(f"Created new tenant {TENANT_NAME} ({TENANT_SLUG}) with ID: {new_tenant_id}")

    # 2. Add user to tenant_users
    cur.execute("""
        SELECT id FROM tenant_users WHERE tenant_id = %s AND user_id = %s;
    """, (new_tenant_id, CUSTOMER_EMAIL))
    user_row = cur.fetchone()
    if user_row:
        print(f"User {CUSTOMER_EMAIL} already in tenant {new_tenant_id}")
    else:
        cur.execute("SELECT uuid_generate_v7();")
        new_user_rec_id = str(cur.fetchone()[0])
        cur.execute("""
            INSERT INTO tenant_users (id, tenant_id, user_id, display_name, role, metadata, created_at, updated_at)
            VALUES (%s, %s, %s, %s, 'owner', '{}'::jsonb, now(), now())
            RETURNING id;
        """, (new_user_rec_id, new_tenant_id, CUSTOMER_EMAIL, CUSTOMER_NAME))
        print(f"Added {CUSTOMER_NAME} ({CUSTOMER_EMAIL}) as 'owner' to tenant {new_tenant_id}")

    # 3. Clone 24 Agents from Master Tenant
    cur.execute("""
        SELECT * FROM agents WHERE tenant_id = %s ORDER BY is_default DESC, agent_key;
    """, (MASTER_TENANT_ID,))
    master_agents = cur.fetchall()
    colnames = [desc[0] for desc in cur.description]
    print(f"Found {len(master_agents)} agents in Master Tenant")

    agent_id_map = {} # old_id -> new_id

    for agent in master_agents:
        agent_dict = dict(zip(colnames, agent))
        old_id = str(agent_dict["id"])
        agent_key = agent_dict["agent_key"]

        cur.execute("SELECT id FROM agents WHERE tenant_id = %s AND agent_key = %s;", (new_tenant_id, agent_key))
        existing_agent = cur.fetchone()
        if existing_agent:
            new_agent_id = str(existing_agent[0])
            agent_id_map[old_id] = new_agent_id
            print(f"Agent '{agent_key}' already exists in new tenant (ID: {new_agent_id})")
            continue

        cur.execute("SELECT uuid_generate_v7();")
        new_agent_id = str(cur.fetchone()[0])
        agent_id_map[old_id] = new_agent_id

        cur.execute("""
            INSERT INTO agents (
                id, agent_key, display_name, owner_id, provider, model,
                context_window, max_tool_iterations, workspace, restrict_to_workspace,
                tools_config, sandbox_config, subagents_config, memory_config,
                compaction_config, context_pruning, other_config, is_default,
                agent_type, status, created_at, updated_at, deleted_at,
                frontmatter, budget_monthly_cents, tenant_id, emoji,
                agent_description, thinking_level, max_tokens, self_evolve,
                skill_evolve, skill_nudge_interval, reasoning_config,
                workspace_sharing, chatgpt_oauth_routing, shell_deny_groups,
                kg_dedup_config, model_fallback
            ) VALUES (
                %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s,
                %s, %s, %s, %s,
                %s, %s, %s, %s,
                %s, %s, now(), now(), NULL,
                %s, %s, %s, %s,
                %s, %s, %s, %s,
                %s, %s, %s,
                %s, %s, %s,
                %s, %s
            );
        """, (
            new_agent_id,
            agent_dict["agent_key"],
            agent_dict["display_name"],
            CUSTOMER_EMAIL,
            agent_dict["provider"],
            agent_dict["model"],
            agent_dict["context_window"],
            agent_dict["max_tool_iterations"],
            agent_dict["workspace"],
            agent_dict["restrict_to_workspace"],
            json.dumps(agent_dict["tools_config"]),
            json.dumps(agent_dict["sandbox_config"]) if agent_dict["sandbox_config"] is not None else None,
            json.dumps(agent_dict["subagents_config"]) if agent_dict["subagents_config"] is not None else None,
            json.dumps(agent_dict["memory_config"]) if agent_dict["memory_config"] is not None else None,
            json.dumps(agent_dict["compaction_config"]) if agent_dict["compaction_config"] is not None else None,
            json.dumps(agent_dict["context_pruning"]) if agent_dict["context_pruning"] is not None else None,
            json.dumps(agent_dict["other_config"]),
            agent_dict["is_default"],
            agent_dict["agent_type"],
            agent_dict["status"],
            agent_dict["frontmatter"],
            agent_dict["budget_monthly_cents"],
            new_tenant_id,
            agent_dict["emoji"],
            agent_dict["agent_description"],
            agent_dict["thinking_level"],
            agent_dict["max_tokens"],
            agent_dict["self_evolve"],
            agent_dict["skill_evolve"],
            agent_dict["skill_nudge_interval"],
            json.dumps(agent_dict["reasoning_config"]),
            json.dumps(agent_dict["workspace_sharing"]),
            json.dumps(agent_dict["chatgpt_oauth_routing"]),
            json.dumps(agent_dict["shell_deny_groups"]),
            json.dumps(agent_dict["kg_dedup_config"]),
            json.dumps(agent_dict["model_fallback"])
        ))
        print(f"Cloned agent: {agent_key} -> {new_agent_id}")

    # 4. Clone agent_context_files (CAPABILITIES.md, SYSTEM.md) for each agent
    cur.execute("""
        SELECT * FROM agent_context_files WHERE tenant_id = %s;
    """, (MASTER_TENANT_ID,))
    context_files = cur.fetchall()
    c_colnames = [desc[0] for desc in cur.description]
    print(f"Found {len(context_files)} context files in Master Tenant")

    for cf in context_files:
        cf_dict = dict(zip(c_colnames, cf))
        old_agent_id = str(cf_dict["agent_id"])
        if old_agent_id not in agent_id_map:
            continue
        new_agent_id = agent_id_map[old_agent_id]
        file_name = cf_dict["file_name"]

        cur.execute("""
            SELECT id FROM agent_context_files WHERE tenant_id = %s AND agent_id = %s AND file_name = %s;
        """, (new_tenant_id, new_agent_id, file_name))
        if cur.fetchone():
            continue

        cur.execute("SELECT uuid_generate_v7();")
        new_cf_id = str(cur.fetchone()[0])
        cur.execute("""
            INSERT INTO agent_context_files (id, agent_id, file_name, content, created_at, updated_at, tenant_id)
            VALUES (%s, %s, %s, %s, now(), now(), %s);
        """, (new_cf_id, new_agent_id, file_name, cf_dict["content"], new_tenant_id))

    print("Cloned all agent context files successfully!")

    # 5. Clone Skills (8 standard skills)
    cur.execute("SELECT * FROM skills WHERE tenant_id = %s;", (MASTER_TENANT_ID,))
    skills = cur.fetchall()
    s_colnames = [desc[0] for desc in cur.description]
    for s in skills:
        s_dict = dict(zip(s_colnames, s))
        slug = s_dict["slug"]

        cur.execute("SELECT id FROM skills WHERE tenant_id = %s AND slug = %s;", (new_tenant_id, slug))
        if cur.fetchone():
            continue

        cur.execute("SELECT uuid_generate_v7();")
        new_skill_id = str(cur.fetchone()[0])
        cur.execute("""
            INSERT INTO skills (
                id, name, slug, description, owner_id, visibility, version,
                status, frontmatter, file_path, file_size, file_hash,
                tags, created_at, updated_at, is_system, deps, enabled, tenant_id
            ) VALUES (
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, now(), now(), %s, %s, %s, %s
            );
        """, (
            new_skill_id, s_dict["name"], s_dict["slug"], s_dict["description"],
            CUSTOMER_EMAIL, s_dict["visibility"], s_dict["version"],
            s_dict["status"], json.dumps(s_dict["frontmatter"]), s_dict["file_path"],
            s_dict["file_size"], s_dict["file_hash"], s_dict["tags"],
            s_dict["is_system"], json.dumps(s_dict["deps"]), s_dict["enabled"], new_tenant_id
        ))
        print(f"Cloned skill: {slug}")

    # 6. Create API Key for customer in api_keys
    import secrets
    import hashlib
    raw_api_key = "goc_" + secrets.token_hex(24)
    key_prefix = raw_api_key[:8]
    key_hash = hashlib.sha256(raw_api_key.encode('utf-8')).hexdigest()

    cur.execute("SELECT id FROM api_keys WHERE tenant_id = %s AND owner_id = %s;", (new_tenant_id, CUSTOMER_EMAIL))
    if not cur.fetchone():
        cur.execute("SELECT uuid_generate_v7();")
        new_key_id = str(cur.fetchone()[0])
        cur.execute("""
            INSERT INTO api_keys (
                id, name, prefix, key_hash, scopes, expires_at,
                last_used_at, revoked, created_by, created_at, updated_at,
                owner_id, tenant_id
            ) VALUES (
                %s, %s, %s, %s, %s, NULL,
                NULL, false, 'system', now(), now(),
                %s, %s
            );
        """, (
            new_key_id,
            f"API Key for {CUSTOMER_NAME}",
            key_prefix,
            key_hash,
            ["*"],
            CUSTOMER_EMAIL,
            new_tenant_id
        ))
        print(f"Created API Key for {CUSTOMER_EMAIL}: {raw_api_key}")
    else:
        print("API Key already exists for customer.")

    conn.commit()
    print("ALL MIGRATION COMMITTED SUCCESSFULLY!")

except Exception as e:
    conn.rollback()
    print(f"ERROR: {e}")
    raise e
finally:
    cur.close()
    conn.close()
