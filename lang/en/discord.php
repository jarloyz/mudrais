<?php

return [

    // ── SHARED ───────────────────────────────────────────────────────────────
    'footer'         => 'MUDRAIS · Role-play Matchmaking System',
    'invalid_id'     => 'Invalid ID.',
    'user_not_found' => 'Could not identify your user.',

    // ── REGISTRO EMBEDS — introNuevo ─────────────────────────────────────────
    'registro_intro_nuevo_title' => 'Welcome to MUDRAIS!',
    'registro_intro_nuevo_desc'  => "Connect with thousands of role-players who match your style.\n\nBy registering you accept our **community terms**: respect, no spam, and content appropriate for the server.\n\n**Registration is free and only takes 2 steps.**\n\nTo begin, **select your gender/pronouns:**",
    'registro_btn_male'          => '♂️ Male',
    'registro_btn_female'        => '♀️ Female',
    'registro_btn_other'         => '⚧ Other / Non-Binary',

    // ── REGISTRO EMBEDS — introEdicion ───────────────────────────────────────
    'registro_edicion_title'         => '✏️ Edit your MUDRAIS Profile',
    'registro_edicion_desc'          => "Editing your **Basic Info** is free.\nEditing your **Archetype Profile** costs **:cost coins**.\n\nYour current balance: **:balance coins**.\n\nSelect which part of your profile you want to modify.",
    'registro_edicion_btn_basics'    => '👤 Edit Basic Info',
    'registro_edicion_btn_archetype' => '🎭 Archetype Profile',

    // ── REGISTRO EMBEDS — introCompletarArquetipo ─────────────────────────────
    'registro_completar_title' => '📋 Complete your Archetype Profile',
    'registro_completar_desc'  => "Your **basic info is already saved**.\n\nYou don't have an archetype profile for this server yet.\n**Completing it is free.** Click to continue.",
    'registro_completar_btn'   => '🎭 Complete Archetype Profile',

    // ── REGISTRO EMBEDS — puenteStep2 ────────────────────────────────────────
    'registro_puente_step2_title' => '✅ Step 1 Complete',
    'registro_puente_step2_desc'  => "Your data has been saved.\n\nClick below to continue with your **writing style and preferences**.",
    'registro_puente_step2_btn'   => 'Continue to Step 2 →',

    // ── REGISTRO EMBEDS — puenteStep2Paginado ────────────────────────────────
    'registro_puente_paginado_title' => '📋 Registration in Progress (:current/:total)',
    'registro_puente_paginado_desc'  => "Excellent! We saved the previous part.\n\nThis archetype requires **more specific data**.\nClick below to continue.",
    'registro_puente_paginado_btn'   => 'Continue Part :next →',

    // ── REGISTRO EMBEDS — error ───────────────────────────────────────────────
    'registro_error_step1_title' => '⚠️ Error in Step 1',
    'registro_error_step1_desc'  => ":error\n\nClick below to correct it.",
    'registro_error_step1_btn'   => '🔁 Fix Step 1',
    'registro_error_step2_title' => '⚠️ Error in Step 2',
    'registro_error_step2_desc'  => ":error\n\nClick below to correct it.",
    'registro_error_step2_btn'   => '🔁 Fix Step 2',

    // ── REGISTRO EMBEDS — éxito ───────────────────────────────────────────────
    'registro_exito_nuevo_title' => '🎉 MUDRAIS Profile Created!',
    'registro_exito_nuevo_desc'  => "Welcome, **:username**! Your profile is ready.\n\nYou can now use `/create` to find role-play partners or start a new game.\n\n**Recommended next step:** Complete the Vault Tutorial to unlock all features.",
    'registro_exito_edit_title'  => '✅ Profile Updated',
    'registro_exito_edit_desc'   => "Your profile has been updated, **:username**.\n\nRemaining balance: **:coins coins**.",

    // ── MODAL STEP 1 ─────────────────────────────────────────────────────────
    'modal_step1_title'              => 'MUDRAIS Registration (Basic Info)',
    'modal_step1_title_error'        => '⚠️ Basic Info — Review your data',
    'modal_step1_label_name'         => 'Name / Nickname',
    'modal_step1_placeholder_name'   => 'E.g.: Alex',
    'modal_step1_label_age'          => 'Age',
    'modal_step1_placeholder_age'    => 'E.g.: 28',
    'modal_step1_label_nationality'  => 'Nationality',
    'modal_step1_placeholder_nat'    => 'E.g.: Mexico',
    'modal_step1_placeholder_gender' => 'Gender: Male / Female / Non-binary / Other',
    'modal_step1_label_about'        => 'Introduction Letter (Community)',
    'modal_step1_placeholder_about'  => 'Express yourself! Add emojis, your story, links...',
    'modal_step1_gender_male'        => 'Male',
    'modal_step1_gender_female'      => 'Female',
    'modal_step1_gender_nonbinary'   => 'Non-binary',
    'modal_step1_gender_other'       => 'Other',

    // ── MODAL STEP 2 (generic fallback) ───────────────────────────────────────
    'modal_step2_title'              => 'Archetype Profile',
    'modal_step2_label_red'          => 'Absolute Limits (Red)',
    'modal_step2_placeholder_red'    => 'Topics forbidden for you. You will never see games with these.',
    'modal_step2_label_yellow'       => 'Topics to Avoid (Yellow)',
    'modal_step2_placeholder_yellow' => 'Max 10, ordered from most to least unpleasant.',
    'modal_step2_label_prefs'        => 'Your Favorites',
    'modal_step2_placeholder_prefs'  => 'Genres, tropes or themes. Max 10, ordered by preference.',
    'modal_step2_label_style'        => 'Your Style Summary',
    'modal_step2_placeholder_style'  => 'Be direct. E.g. 3rd person, psychological drama, slow burn...',
    'modal_step2_label_schedule'     => 'Availability / Schedule',
    'modal_step2_placeholder_schedule' => 'E.g. weekends, evenings UTC-5, ~3h/week',

    // ── VAULT APPROVAL EMBEDS ────────────────────────────────────────────────
    'vault_approval_preview_title'   => 'Vault Preview — Semantic Review',
    'vault_approval_field_name_es'   => 'Name (ES)',
    'vault_approval_field_name_en'   => 'Name (EN)',
    'vault_approval_field_optimized' => 'Optimized Text (Vector)',
    'vault_approval_field_tags'      => 'Tags (Taxonomy)',
    'vault_approval_tags_none'       => 'None',
    'vault_approval_footer_expires'  => '⏱ This preview expires in 15 minutes.',
    'vault_approval_btn_approve'     => '✅ Accept and Save',
    'vault_approval_btn_reject'      => '❌ Reject',
    'vault_processing_title'         => '⏳ Processing...',
    'vault_processing_desc'          => 'We are creating and vectorizing your Vault. This will take a few seconds.',
    'vault_rejected_title'           => '❌ Creation Cancelled',
    'vault_rejected_desc'            => 'The Vault has not been created. Data has been securely discarded.',
    'vault_approved_title'           => '✅ Vault Successfully Created',
    'vault_approved_desc'            => "Your new role-play space is ready: <#:channel>\nEnjoy the adventure!",

    // ── MIDDLEWARE — ENERGY ───────────────────────────────────────────────────
    'energy_insufficient' => '⚡ You need **:cost** energy to use `/:command`. You have **:energy**.',

    // ── MIDDLEWARE — PERMISSIONS ──────────────────────────────────────────────
    'permission_denied'        => '🚫 You do not have permission to use `/:command` on this server.',
    'archetype_register_title' => 'Registration Required',
    'archetype_register_desc'  => "You are not registered in the **:archetype** archetype.\nYou must register your profile to interact in this channel.",
    'archetype_register_btn'   => '📝 Register',

    // ── CONTROLLER — /register ────────────────────────────────────────────────
    'tutorial_required'      => '⚠️ You must complete the **Vault Tutorial** before editing your profile.',
    'cost_resolve_error'     => '⚠️ Could not determine the editing cost. Please try again later.',
    'edit_cost_insufficient' => '💸 Editing your profile costs **:cost coins**. Your current balance is **:coin**.',

    // ── CONTROLLER — /profile ─────────────────────────────────────────────────
    'ficha_modal_title'       => 'Your MUDRAIS Identity Profile',
    'ficha_field_label'       => 'Your Identity Profile',
    'ficha_field_placeholder' => 'Paste your completed profile here...',

    // ── CONTROLLER — /create-vault ────────────────────────────────────────────
    'create_vault_modal_title'       => 'Create New Vault',
    'create_vault_modal_title_paged' => 'Create New Vault (Step :page of :total)',
    'vault_part_completed'           => '✅ Part :page completed. Click below to continue.',
    'vault_continue_btn'             => 'Continue (Step :next of :total) →',

    // ── CONTROLLER — registro step 2 ─────────────────────────────────────────
    'step2_part_completed'  => '✅ Part **:page/:total** completed. Continue to finish your profile.',
    'step2_continue_btn'    => 'Continue (Step :next of :total) →',
    'step2_age_invalid'     => 'The **age** must be a number between 13 and 99.',
    'step2_fields_required' => 'The following fields are required: :fields.',
    'step2_retry_btn'       => 'Retry Step 2 →',

    // ── CONTROLLER — archetype profile modal ─────────────────────────────────
    'ficha_arquetipo_title'       => 'Archetype Profile',
    'ficha_arquetipo_title_paged' => 'Archetype Profile (Step :page of :total)',

    // ── CONTROLLER — /create context ─────────────────────────────────────────
    'create_context_no_vault'      => '⚠️ This channel does not belong to any active Vault. Use the command from the Vault channel.',
    'create_context_invalid_type'  => '⚠️ Invalid type. Select an option from the autocomplete.',
    'create_context_type_mismatch' => '⚠️ The selected type does not match this Vault\'s archetype.',
    'create_context_empty_list'    => "There are no **:type** in this Vault yet.\nBe the first to create one!",
    'create_context_list'          => "**:count** element(s) in this Vault:\n\n:lines",
    'create_context_btn'               => 'Create :type →',
    'create_context_title'             => 'New Context',
    'create_context_title_paged'       => 'New Context (Step :page of :total)',
    'create_context_choice_title'      => '✨ Create Character',
    'create_context_choice_desc'       => "How do you want to define your character?\n\n📋 **Quick form** — fill in a form with the key fields.\n🎙️ **AI Interview** — tell me about your character in natural language and the AI builds it with you.",
    'create_context_choice_btn_modal'  => '📋 Quick form',
    'context_part_completed'       => '✅ Part :page completed. Click below to continue.',
    'context_continue_btn'         => 'Continue (Step :next of :total) →',
    'context_no_character'         => 'Character not found.',
    'context_no_attributes'        => 'This character type has no configurable attributes.',
    'context_configure_title'      => 'Configure Attributes',

    // ── CONTROLLER — /actividad ───────────────────────────────────────────────
    'actividad_modal_title'           => 'New Activity — :vault',
    'actividad_modal_title_short'     => 'New Activity',
    'actividad_choice_title'          => '✨ Create Activity',
    'actividad_choice_desc'           => "How do you want to define your activity?\n\n📋 **Quick form** — fill in a form with the key fields.\n🎙️ **AI Interview** — describe the activity in natural language and the AI builds it with you.",
    'actividad_choice_btn_modal'      => '📋 Quick form',
    'actividad_label_title'           => 'What are you looking for?',
    'actividad_placeholder_title' => 'E.g.: Looking for a tank for epic dungeon',
    'actividad_label_extra'       => 'Extra Context (Optional)',
    'actividad_placeholder_extra' => 'E.g.: Weekends 8pm, level 80+...',
    'actividad_session_expired'   => '⏳ Session expired. Repeat the `/actividad crear` command.',
    'actividad_no_vault'          => '⚠️ This channel does not belong to any active Vault. Use the command from the Vault channel.',
    'actividad_no_type'           => '⚠️ This Vault has no activity types configured. Contact an administrator.',

    // ── BOT BETA — INTERVIEW IN THREAD ───────────────────────────────────────
    'interview_thread_created' => '💬 Your interview is ready. Write directly in the private thread ↑',

    // ── DYNAMIC INTERVIEWER AGENT ─────────────────────────────────────────────
    'interview_form_bridge_title'     => '✅ Great! We have your conversational profile.',
    'interview_form_bridge_desc'      => "We captured your roleplay style and preferences.\n\nJust a few quick structured questions left. Last step!",
    'interview_form_bridge_btn'       => '📋 Fill Structured Fields',
    'interview_form_title'            => 'Profile Details',
    'interview_awaiting_form'         => '📋 You have pending structured fields. Click the button to fill them in.',
    'interview_btn_label'             => '🎙️ AI Interview',
    'interview_beta_btn_label'        => '🎙️ Narrative Interview',
    'voice_interview_btn_label'       => 'Voice Interview',
    'interview_beta_thread_creating'  => '⏳ Creating your private interview thread...',
    'interview_opening_question'      => 'Hi :username! 👋 Tell me freely everything you want about how you roleplay: your favorite genres, your writing style, what you enjoy, what you prefer to avoid, what your ideal partner would be like... There\'s no wrong answer, just be yourself!',
    'interview_opening_avatar'        => 'Hi :username! 👋 Let\'s create your character. Tell me freely about them: their personality, backstory, way of being? Feel free to mention their name, background, motivations, character traits... anything you\'d like.',
    'interview_opening_activity'      => 'Hi :username! 👋 Tell me about the activity you want to create: what kind of story are you looking for? What tone, pace, or themes do you prefer? Describe freely what you have in mind.',
    'interview_respond_instruction'   => '📝 To answer use: `/interview answer: <your text>`',
    'interview_summary_title'         => '📋 Profile Summary',
    'interview_summary_desc'          => "Based on our conversation, here's what I gathered about your roleplay profile.\n\nShall we save it?",
    'interview_confirm_btn'           => '✅ Confirm & Save',
    'interview_retry_btn'             => '🔄 Try Again',
    'interview_cancel_btn'            => '❌ Cancel',
    'interview_cancelled'             => '❌ Interview cancelled. You can start it again whenever you want.',
    'interview_expired'               => '⏳ Your interview session expired. Use `/register` to start again.',
    'interview_no_player'             => '⚠️ Complete basic registration with `/register` first.',
    'interview_resumed'               => '💬 **Current question:**',
    'interview_already_confirmed'     => '✅ You already confirmed your profile. Use `/register` if you want to edit it.',
    'interview_turn_label'            => 'Turn :turn',
    // ── SetupOnboarding ──────────────────────────────────────────────────────
    'setup_onboarding_success'    => '✅ Onboarding channel configured. Private interview threads will be created in <#:channel_id>.',
    'setup_onboarding_no_channel' => '❌ Could not detect the channel. Run the command directly from the channel you want to configure.',
    'setup_onboarding_no_guild'   => '❌ This command can only be used inside a server.',
    'setup_onboarding_error'      => '❌ Internal error saving the configuration. Please try again.',

    'interview_processing_registration' => '✅ All done! We\'re processing your registration, please wait a moment...',
    'interview_energy_depleted'         => '⚡ You don\'t have enough energy to continue the interview. Recharge and start a new session when you\'re ready.',
    'interview_error_retry'           => '⚠️ An error occurred processing your answer. Please try again with `/interview answer: <your text>`.',
    'interview_rate_limit_fatal'      => '⏳ The AI service is currently overloaded. Please try again in a few minutes with `/interview answer: <your text>`.',
    'interview_error_fatal'           => '❌ An unexpected error occurred during your interview. Your session is still active — try again with `/interview answer: <your text>`.',
    'interview_question_explain'      => 'Sure! **:label**: :hint',
    'interview_question_redirect'     => 'Got it, at this point I\'m asking about **:label**. Can you tell me about it?',
    'interview_question_generic'      => "Great question! We only use this information to connect you with the most compatible roleplay partners — no one else sees it.\n\nEach detail (what you enjoy, what you prefer to avoid, your style) acts as a filter working in your favor. Whenever you're ready, tell me about your preferences.",
    'interview_off_topic_redirect'    => '⚡ Let\'s get back to the interview. Tell me about your roleplay preferences.',
    'interview_manipulation_redirect' => '⚡ Let\'s get back to the interview. Tell me about your roleplay preferences.',

    // ── VOICE INTERVIEW ──────────────────────────────────────────────────────
    'voice_talkator_fallback_0'       => 'That is really interesting to hear. I can see how that shapes who you are. Let me make a note of it.',
    'voice_talkator_fallback_1'       => 'Fascinating, I did not expect that perspective. It adds a lot of color to your profile. Noted.',
    'voice_talkator_fallback_2'       => 'I can tell that means a lot to you. Those kinds of details are exactly what we are looking for. Good to know.',
    'voice_talkator_fallback_3'       => 'That is a great thing to share. It gives a lot of depth to your profile. I will keep that in mind.',
    'voice_talkator_fallback_4'       => 'Interesting, that fits well with what we have been exploring. I am glad you brought it up. Noted.',
    'voice_off_topic_redirect'        => 'Let\'s get back to the interview.',
    'voice_error_processing'          => 'Sorry, something went wrong. Continue whenever you\'re ready.',
    'voice_archetype_complete'        => 'Excellent. Let\'s move on to the next profile.',
    'voice_session_complete'          => 'We\'ve finished all your profiles. See you later.',
    'voice_all_archetypes_complete'   => 'You already have all your profiles complete in this server.',
    'voice_no_player'                 => 'We couldn\'t find your account. Please register in the server first.',
    'voice_session_not_found'         => 'Voice session not found.',
    'voice_session_expired'           => 'The voice session has expired.',
    'voice_guild_not_found'           => 'Server not recognized.',
    'voice_interview_already_active'  => '⚠️ You already have an active voice session in this server.',
    'voice_interview_no_guild'        => '❌ This command only works inside a server.',

    // ── GUILD ACCESS ─────────────────────────────────────────────────────────
    'guild_not_registered'            => '⛔ Your guild is not registered in MUDRAIS. Please contact the MUDRAIS team to enable it.',

    // ── HELP ─────────────────────────────────────────────────────────────────
    'help_title'       => '📖 How does MUDRAIS work?',
    'help_description' => "MUDRAIS connects roleplay players using artificial intelligence.\nChoose an archetype, create your vault, and start finding play partners.",
    'help_setup'       => "**⚙️ Initial setup (server admin)**\n1. Go to the website and auth with Discord\n2. From the web, click **Invite Bot**\n3. Run `/create-vault` and choose an archetype:\n   • **Roleplay Text** — collaborative written roleplay\n   • **Semantic Reading** — reading with semantic analysis\n   • **Team Matcher** — team building",
    'help_player'      => "**🎮 Player commands**\n`/register` — Create or edit your profile *(always available)*\n`/status` — Check your energy, coins and ELO\n`/create` — Add characters, locations or lore to the vault\n`/search` — Find compatible partners or activities\n`/actividad` — Publish a group search",
    'help_footer'      => 'MUDRAIS · Use /register to get started',

];
