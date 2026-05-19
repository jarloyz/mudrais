<?php

return [

    // ── COMPARTIDO ────────────────────────────────────────────────────────────
    'footer'         => 'MUDRAIS · Sistema de Emparejamiento de Rol',
    'invalid_id'     => 'ID inválido.',
    'user_not_found' => 'No se pudo identificar tu usuario.',

    // ── REGISTRO EMBEDS — introNuevo ─────────────────────────────────────────
    'registro_intro_nuevo_title' => '¡Bienvenido a MUDRAIS!',
    'registro_intro_nuevo_desc'  => "Conecta con miles de roleros afines a tu estilo.\n\nAl registrarte aceptas nuestros **términos de comunidad**: respeto, no spam y contenido apropiado para el servidor.\n\n**El registro es gratuito y solo tarda 2 pasos.**\n\nPara comenzar, **selecciona tu sexo/pronombres:**",
    'registro_btn_male'          => '♂️ Hombre',
    'registro_btn_female'        => '♀️ Mujer',
    'registro_btn_other'         => '⚧ Otro / No Binario',

    // ── REGISTRO EMBEDS — introEdicion ───────────────────────────────────────
    'registro_edicion_title'          => '✏️ Editar tu Perfil MUDRAIS',
    'registro_edicion_desc'           => "Editar tus **Datos Básicos** no tiene costo.\nEditar tu **Ficha de Arquetipo** tiene un costo de **:cost monedas**.\n\nTu saldo actual: **:balance monedas**.\n\nSelecciona qué parte de tu perfil deseas modificar.",
    'registro_edicion_btn_basics'     => '👤 Editar Datos Básicos',
    'registro_edicion_btn_archetype'  => '🎭 Ficha de Arquetipo',

    // ── REGISTRO EMBEDS — introCompletarArquetipo ─────────────────────────────
    'registro_completar_title' => '📋 Completa tu Ficha de Arquetipo',
    'registro_completar_desc'  => "Tus **datos básicos ya están guardados**.\n\nAún no tienes una ficha de arquetipo para este servidor.\n**Completarla es gratuito.** Haz clic para continuar.",
    'registro_completar_btn'   => '🎭 Completar Ficha de Arquetipo',

    // ── REGISTRO EMBEDS — puenteStep2 ────────────────────────────────────────
    'registro_puente_step2_title' => '✅ Paso 1 Completado',
    'registro_puente_step2_desc'  => "Tus datos han sido guardados.\n\nHaz clic abajo para continuar con tu **estilo de escritura y preferencias**.",
    'registro_puente_step2_btn'   => 'Continuar al Paso 2 →',

    // ── REGISTRO EMBEDS — puenteStep2Paginado ────────────────────────────────
    'registro_puente_paginado_title' => '📋 Registro en Progreso (:current/:total)',
    'registro_puente_paginado_desc'  => "¡Excelente! Hemos guardado la parte anterior.\n\nEste arquetipo requiere **más datos específicos**.\nHaz clic abajo para continuar.",
    'registro_puente_paginado_btn'   => 'Continuar Parte :next →',

    // ── REGISTRO EMBEDS — error ───────────────────────────────────────────────
    'registro_error_step1_title' => '⚠️ Error en el Paso 1',
    'registro_error_step1_desc'  => ":error\n\nHaz clic abajo para corregirlo.",
    'registro_error_step1_btn'   => '🔁 Corregir Paso 1',
    'registro_error_step2_title' => '⚠️ Error en el Paso 2',
    'registro_error_step2_desc'  => ":error\n\nHaz clic abajo para corregirlo.",
    'registro_error_step2_btn'   => '🔁 Corregir Paso 2',

    // ── REGISTRO EMBEDS — éxito ───────────────────────────────────────────────
    'registro_exito_nuevo_title' => '🎉 ¡Perfil MUDRAIS Creado!',
    'registro_exito_nuevo_desc'  => "¡Bienvenido, **:username**! Tu ficha está lista.\n\nAhora puedes usar `/create` para encontrar compañeros de rol o iniciar una nueva partida.\n\n**Siguiente paso recomendado:** Completa el Vault Tutorial para desbloquear todas las funciones.",
    'registro_exito_edit_title'  => '✅ Ficha Actualizada',
    'registro_exito_edit_desc'   => "Tu perfil ha sido actualizado, **:username**.\n\nSaldo restante: **:coins monedas**.",

    // ── MODAL STEP 1 ─────────────────────────────────────────────────────────
    'modal_step1_title'              => 'Registro MUDRAIS (Datos Básicos)',
    'modal_step1_title_error'        => '⚠️ Datos Básicos — Revisa los datos',
    'modal_step1_label_name'         => 'Nombre / Apodo',
    'modal_step1_placeholder_name'   => 'Ej: Alex',
    'modal_step1_label_age'          => 'Edad',
    'modal_step1_placeholder_age'    => 'Ej: 28',
    'modal_step1_label_nationality'  => 'Nacionalidad',
    'modal_step1_placeholder_nat'    => 'Ej: México',
    'modal_step1_placeholder_gender' => 'Género: Hombre / Mujer / No binario / Otro',
    'modal_step1_label_about'        => 'Carta de Presentación (Comunidad)',
    'modal_step1_placeholder_about'  => '¡Exprésate! Pon emojis, tu historia, links...',
    'modal_step1_gender_male'        => 'Hombre',
    'modal_step1_gender_female'      => 'Mujer',
    'modal_step1_gender_nonbinary'   => 'No binario',
    'modal_step1_gender_other'       => 'Otro',

    // ── MODAL STEP 2 (fallback genérico) ──────────────────────────────────────
    'modal_step2_title'              => 'Ficha de Arquetipo',
    'modal_step2_label_red'          => 'Límites Absolutos (Rojo)',
    'modal_step2_placeholder_red'    => 'Temas prohibidos para ti. Nunca verás partidas con estos.',
    'modal_step2_label_yellow'       => 'Temas a Evitar (Amarillo)',
    'modal_step2_placeholder_yellow' => 'Máx 10, ordenados de más a menos incómodos.',
    'modal_step2_label_prefs'        => 'Tus Favoritos',
    'modal_step2_placeholder_prefs'  => 'Géneros, tropos o temáticas. Máx 10, ordenados por preferencia.',
    'modal_step2_label_style'        => 'Tu Estilo en Resumen',
    'modal_step2_placeholder_style'  => 'Sé directo. Ej: 3ª persona, drama psicológico, slow burn...',
    'modal_step2_label_schedule'     => 'Disponibilidad / Horario',
    'modal_step2_placeholder_schedule' => 'Ej: fines de semana, noches UTC-5, ~3h/semana',

    // ── VAULT APPROVAL EMBEDS ────────────────────────────────────────────────
    'vault_approval_preview_title'   => 'Vault Preview — Revisión Semántica',
    'vault_approval_field_name_es'   => 'Nombre (ES)',
    'vault_approval_field_name_en'   => 'Nombre (EN)',
    'vault_approval_field_optimized' => 'Texto Optimizado (Vectorial)',
    'vault_approval_field_tags'      => 'Tags (Taxonomía)',
    'vault_approval_tags_none'       => 'Ninguno',
    'vault_approval_footer_expires'  => '⏱ Esta vista previa expira en 15 minutos.',
    'vault_approval_btn_approve'     => '✅ Aceptar y Guardar',
    'vault_approval_btn_reject'      => '❌ Rechazar',
    'vault_processing_title'         => '⏳ Procesando...',
    'vault_processing_desc'          => 'Estamos creando y vectorizando tu Vault. Esto tomará unos segundos.',
    'vault_rejected_title'           => '❌ Creación Cancelada',
    'vault_rejected_desc'            => 'El Vault no ha sido creado. Los datos han sido descartados de forma segura.',
    'vault_approved_title'           => '✅ Vault Creado Exitosamente',
    'vault_approved_desc'            => "Tu nuevo espacio de rol está listo: <#:channel>\n¡Disfruta de la aventura!",

    // ── MIDDLEWARE — ENERGÍA ──────────────────────────────────────────────────
    'energy_insufficient' => '⚡ Necesitas **:cost** de energía para usar `/:command`. Tienes **:energy**.',

    // ── MIDDLEWARE — PERMISOS ─────────────────────────────────────────────────
    'permission_denied'         => '🚫 No tienes permiso para usar `/:command` en este servidor.',
    'archetype_register_title'  => 'Registro Requerido',
    'archetype_register_desc'   => "No estás registrado en el arquetipo **:archetype**.\nDebes registrar tu ficha para interactuar en este canal.",
    'archetype_register_btn'    => '📝 Registrarse',

    // ── CONTROLLER — /register ────────────────────────────────────────────────
    'tutorial_required'      => '⚠️ Debes completar el **Vault Tutorial** antes de editar tu ficha.',
    'cost_resolve_error'     => '⚠️ No se pudo determinar el costo de edición. Inténtalo más tarde.',
    'edit_cost_insufficient' => '💸 Editar tu ficha cuesta **:cost monedas**. Tu saldo actual es **:coin**.',

    // ── CONTROLLER — /profile ─────────────────────────────────────────────────
    'ficha_modal_title'       => 'Tu Ficha de Identidad MUDRAIS',
    'ficha_field_label'       => 'Tu Ficha de Identidad',
    'ficha_field_placeholder' => 'Pega aquí tu ficha rellena...',

    // ── CONTROLLER — /create-vault ────────────────────────────────────────────
    'create_vault_modal_title'       => 'Crear Nuevo Vault',
    'create_vault_modal_title_paged' => 'Crear Nuevo Vault (Paso :page de :total)',
    'vault_part_completed'           => '✅ Parte :page completada. Haz clic abajo para continuar.',
    'vault_continue_btn'             => 'Continuar (Paso :next de :total) →',

    // ── CONTROLLER — registro step 2 ─────────────────────────────────────────
    'step2_part_completed'  => '✅ Parte **:page/:total** completada. Continúa para terminar tu ficha.',
    'step2_continue_btn'    => 'Continuar (Paso :next de :total) →',
    'step2_age_invalid'     => 'La **edad** debe ser un número entre 13 y 99.',
    'step2_fields_required' => 'Los siguientes campos son obligatorios: :fields.',
    'step2_retry_btn'       => 'Reintentar Paso 2 →',

    // ── CONTROLLER — ficha arquetipo ─────────────────────────────────────────
    'ficha_arquetipo_title'       => 'Ficha de Arquetipo',
    'ficha_arquetipo_title_paged' => 'Ficha de Arquetipo (Paso :page de :total)',

    // ── CONTROLLER — /create context ─────────────────────────────────────────
    'create_context_no_vault'      => '⚠️ Este canal no pertenece a ningún Vault activo. Usa el comando desde el canal del Vault.',
    'create_context_invalid_type'  => '⚠️ Tipo inválido. Selecciona una opción del autocomplete.',
    'create_context_type_mismatch' => '⚠️ El tipo seleccionado no corresponde al arquetipo de este Vault.',
    'create_context_empty_list'    => "No hay **:type** en este Vault todavía.\n¡Sé el primero en crear uno!",
    'create_context_list'          => "**:count** elemento(s) en este Vault:\n\n:lines",
    'create_context_btn'               => 'Crear :type →',
    'create_context_title'             => 'Nuevo Contexto',
    'create_context_title_paged'       => 'Nuevo Contexto (Paso :page de :total)',
    'create_context_choice_title'      => '✨ Crear Personaje',
    'create_context_choice_desc'       => "¿Cómo quieres definir tu personaje?\n\n📋 **Modal rápido** — rellena un formulario con los campos clave.\n🎙️ **Entrevista IA** — cuéntame sobre tu personaje en lenguaje natural y la IA lo construye contigo.",
    'create_context_choice_btn_modal'  => '📋 Modal rápido',
    'context_part_completed'       => '✅ Parte :page completada. Haz clic abajo para continuar.',
    'context_continue_btn'         => 'Continuar (Paso :next de :total) →',
    'context_no_character'         => 'No se encontró el personaje.',
    'context_no_attributes'        => 'Este tipo de personaje no tiene atributos configurables.',
    'context_configure_title'      => 'Configurar Atributos',

    // ── CONTROLLER — /actividad ───────────────────────────────────────────────
    'actividad_modal_title'           => 'Nueva Actividad — :vault',
    'actividad_modal_title_short'     => 'Nueva Actividad',
    'actividad_choice_title'          => '✨ Crear Actividad',
    'actividad_choice_desc'           => "¿Cómo quieres definir tu actividad?\n\n📋 **Modal rápido** — rellena un formulario con los campos clave.\n🎙️ **Entrevista IA** — descríbeme la actividad en lenguaje natural y la IA la construye contigo.",
    'actividad_choice_btn_modal'      => '📋 Modal rápido',
    'actividad_label_title'           => '¿Qué estás buscando?',
    'actividad_placeholder_title' => 'Ej: Busco tanque para mazmorra épica',
    'actividad_label_extra'       => 'Contexto Extra (Opcional)',
    'actividad_placeholder_extra' => 'Ej: Fines de semana 8pm, nivel 80+...',
    'actividad_session_expired'   => '⏳ La sesión expiró. Repite el comando `/actividad crear`.',
    'actividad_no_vault'          => '⚠️ Este canal no pertenece a ningún Vault activo. Usa el comando desde el canal del Vault.',
    'actividad_no_type'           => '⚠️ Este Vault no tiene tipos de actividad configurados. Contacta a un administrador.',

    // ── BOT BETA — ENTREVISTA EN HILO ────────────────────────────────────────
    'interview_thread_created' => '💬 Tu entrevista está lista. Escribe directamente en el hilo privado ↑',

    // ── DYNAMIC INTERVIEWER AGENT ─────────────────────────────────────────────
    'interview_form_bridge_title'     => '✅ ¡Perfecto! Ya tenemos tu perfil conversacional.',
    'interview_form_bridge_desc'      => "Hemos capturado tu estilo y preferencias de roleplay.\n\nSolo faltan unas preguntas rápidas con opciones específicas. ¡Es el último paso!",
    'interview_form_bridge_btn'       => '📋 Completar Campos Estructurados',
    'interview_form_title'            => 'Detalles del Perfil',
    'interview_awaiting_form'         => '📋 Tienes campos estructurados pendientes. Haz clic en el botón para completarlos.',
    'interview_btn_label'             => '🎙️ Entrevista IA',
    'interview_beta_btn_label'        => '🎙️ Entrevista Narrativa',
    'voice_interview_btn_label'       => 'Entrevista de Voz',
    'interview_beta_thread_creating'  => '⏳ Creando tu hilo privado de entrevista...',
    'interview_opening_question'      => '¡Hola :username! 👋 Para empezar, cuéntame libremente todo lo que quieras sobre cómo eres en el rol: tus géneros favoritos, tu estilo de escritura, lo que disfrutas, lo que prefieres evitar, cómo quisieras que fuera tu compañero ideal... No hay respuesta incorrecta, ¡sé tú mismo!',
    'interview_opening_avatar'        => '¡Hola :username! 👋 Vamos a crear tu personaje. Cuéntame libremente sobre él: ¿cómo es su personalidad, su historia, su forma de ser? Puedes mencionar nombre, trasfondo, motivaciones, rasgos de carácter... todo lo que quieras.',
    'interview_opening_activity'      => '¡Hola :username! 👋 Cuéntame sobre la actividad que quieres crear: ¿qué tipo de historia buscas? ¿qué tono, ritmo o temáticas prefieres? Describe libremente lo que tienes en mente.',
    'interview_respond_instruction'   => '📝 Para responder usa: `/entrevista respuesta: <tu texto>`',
    'interview_summary_title'         => '📋 Resumen de tu Perfil',
    'interview_summary_desc'          => "Basándome en nuestra conversación, esto es lo que recopilé sobre tu perfil de roleplay.\n\n¿Lo guardamos?",
    'interview_confirm_btn'           => '✅ Confirmar y Guardar',
    'interview_retry_btn'             => '🔄 Reintentar',
    'interview_cancel_btn'            => '❌ Cancelar',
    'interview_cancelled'             => '❌ Entrevista cancelada. Puedes iniciarla de nuevo cuando quieras.',
    'interview_expired'               => '⏳ La sesión de entrevista expiró. Usa `/registro` para comenzar de nuevo.',
    'interview_no_player'             => '⚠️ Primero completa el registro básico con `/registro`.',
    'interview_resumed'               => '💬 **Pregunta actual:**',
    'interview_already_confirmed'     => '✅ Ya confirmaste tu perfil. Usa `/registro` si quieres editarlo.',
    'interview_turn_label'            => 'Turno :turn',
    // ── SetupOnboarding ──────────────────────────────────────────────────────
    'setup_onboarding_success'    => '✅ Canal de entrevistas configurado. Los hilos privados de onboarding se crearán en <#:channel_id>.',
    'setup_onboarding_no_channel' => '❌ No se pudo detectar el canal. Ejecuta el comando directamente desde el canal que quieres configurar.',
    'setup_onboarding_no_guild'   => '❌ Este comando solo puede ejecutarse dentro de un servidor.',
    'setup_onboarding_error'      => '❌ Error interno al guardar la configuración. Intenta de nuevo.',

    'interview_processing_registration' => '✅ ¡Todo listo! Estamos procesando tu registro, espera un momento...',
    'interview_energy_depleted'         => '⚡ No tienes suficiente energía para continuar la entrevista. Recarga energía e inicia una nueva sesión cuando estés listo.',
    'interview_error_retry'           => '⚠️ Ocurrió un error procesando tu respuesta. Por favor, intenta de nuevo con `/entrevista respuesta: <tu texto>`.',
    'interview_rate_limit_fatal'      => '⏳ El servicio de IA está saturado en este momento. Por favor, intenta de nuevo en unos minutos con `/entrevista respuesta: <tu texto>`.',
    'interview_error_fatal'           => '❌ Ocurrió un error inesperado en la entrevista. Tu sesión sigue activa — intenta de nuevo con `/entrevista respuesta: <tu texto>`.',
    'interview_question_explain'      => '¡Claro! **:label**: :hint',
    'interview_question_redirect'     => 'Entendido, en este punto te pregunto sobre **:label**. ¿Puedes contarme al respecto?',
    'interview_question_generic'      => "¡Buena pregunta! Esta información la usamos únicamente para conectarte con los compañeros de roleplay más compatibles contigo — nadie más la ve.\n\nCada detalle (lo que te gusta, lo que prefieres evitar, tu estilo) es como un filtro que trabaja a tu favor. Cuando estés listo, cuéntame sobre tus preferencias.",
    'interview_off_topic_redirect'    => '⚡ Sigamos con la entrevista. Cuéntame sobre tus preferencias de roleplay.',
    'interview_manipulation_redirect' => '⚡ Sigamos con la entrevista. Cuéntame sobre tus preferencias de roleplay.',

    // ── VOICE INTERVIEW ──────────────────────────────────────────────────────
    'voice_talkator_fallback_0'       => 'Ya veo, qué interesante eso que comentas. Tiene mucho sentido que así sea. Déjame anotarlo.',
    'voice_talkator_fallback_1'       => 'Me parece fascinante esa perspectiva. No lo había pensado de esa manera. Lo tomo en cuenta.',
    'voice_talkator_fallback_2'       => 'Claro, eso dice bastante de ti. Es justo el tipo de detalle que nos sirve. Lo dejo registrado.',
    'voice_talkator_fallback_3'       => 'Qué curioso, eso encaja muy bien con lo que exploramos. Me alegra que lo hayas mencionado. Lo anoto.',
    'voice_talkator_fallback_4'       => 'Interesante, hay mucho valor en lo que describes. Eso le da mucha profundidad a tu perfil. Bien.',
    'voice_off_topic_redirect'        => 'Sigamos con la entrevista.',
    'voice_error_processing'          => 'Disculpa, hubo un problema. Continúa cuando estés listo.',
    'voice_archetype_complete'        => 'Excelente. Pasemos al siguiente perfil.',
    'voice_session_complete'          => 'Hemos terminado con todos tus perfiles. Hasta pronto.',
    'voice_all_archetypes_complete'   => 'Ya tienes todos tus perfiles completos en este servidor.',
    'voice_no_player'                 => 'No encontramos tu cuenta. Primero regístrate en el servidor.',
    'voice_session_not_found'         => 'Sesión de voz no encontrada.',
    'voice_session_expired'           => 'La sesión de voz ha expirado.',
    'voice_guild_not_found'           => 'Servidor no reconocido.',
    'voice_interview_already_active'  => '⚠️ Ya tienes una sesión de voz activa en este servidor.',
    'voice_interview_no_guild'        => '❌ Este comando solo funciona dentro de un servidor.',

];
