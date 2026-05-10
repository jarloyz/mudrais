# MUDRAIS — Guía del Usuario

Bienvenido a **MUDRAIS** (Modular Universal Dynamic Roleplay AI System).
Esta guía explica cómo funciona el sistema, qué puedes hacer y cómo empezar.

---

## ¿Qué es MUDRAIS?

MUDRAIS es un bot de Discord con inteligencia artificial que te ayuda a encontrar
**compañeros de juego, lectura o cualquier actividad colaborativa** que sean
verdaderamente compatibles contigo.

No usa un sistema de filtros manuales. Usa vectores semánticos — el mismo tipo de
tecnología que los motores de búsqueda modernos — para entender tu estilo, tus
preferencias y lo que estás buscando, y compararlo con todos los demás miembros
del servidor.

**¿Para qué sirve?**
- Encontrar un compañero de roleplay con tu mismo estilo de escritura
- Encontrar alguien que quiera leer los mismos libros que tú
- Buscar un equipo de gaming compatible con tu nivel y plataforma
- Publicar una actividad (partida, club de lectura, proyecto) y que el sistema
  te mande a los jugadores más compatibles

---

## ¿Cómo funciona? (versión simple)

1. **Te registras** — Le dices al bot qué tipo de jugador/lector eres, en texto libre.
   El bot lo analiza con IA y crea tu "firma semántica".

2. **Creas un contexto** — Describes tu personaje, tu libro favorito, tu perfil de gamer.
   Esto es tu "contexto" (también llamado Avatar en el sistema).

3. **Buscas o publicas una actividad** — Puedes buscar a alguien compatible contigo,
   o publicar una búsqueda para que otros te encuentren a ti.

4. **El motor hace el match** — MUDRAIS compara vectores semánticos y devuelve
   los resultados más compatibles, con un porcentaje de afinidad.

---

## Primeros pasos

### Paso 1 — Regístrate

```
/registro
```

El bot abrirá un formulario de dos pasos:

**Paso 1/2:**
- Tu edad (para clasificación de contenido)
- Nacionalidad
- Nivel de experiencia en el tipo de actividad de tu servidor
- Horarios disponibles
- Extensión de tus respuestas/participación (del 1 al 5)

**Paso 2/2:**
- **Límites absolutos** — Temas que nunca quieres ver en una actividad
  *(Ej: "gore explícito, trauma infantil")*
- **Preferencias a evitar** — Cosas que toleras pero prefieres no
  *(Ej: "romance excesivo, humor absurdo")*
- **Tus favoritos** — Géneros, estilos, tropos que te encantan
  *(Ej: "fantasía épica, misterio, horror cósmico")*
- **Tu estilo** — Cómo describes cómo juegas/lees/participas en pocas líneas
  *(Ej: "tercera persona, drama psicológico, desarrollo lento de personajes")*
- **Carta de presentación** — Cuéntate a la comunidad (emojis, historia, links — libre)

El bot procesará tu perfil, lo traducirá al inglés para el motor vectorial y
te confirmará con un mensaje privado cuando esté listo.

> 💡 Tip: Sé específico en "Tus favoritos" y "Tu estilo". Cuanto más concreto,
> mejor será el matchmaking.

---

### Paso 2 — Crea tu contexto (personaje, libro, juego…)

```
/create type:[tipo de entidad]
```

Esto crea tu "contexto" dentro del servidor. Dependiendo del tipo de servidor puede ser:
- Tu **personaje** de roleplay
- Un **libro** que quieres recomendar o releer con alguien
- Tu **perfil de gamer**

El bot abrirá un formulario con los campos específicos del tipo de entidad.
Rellénalos con detalle — estos datos son los que el motor vectorial usa
para hacer el matchmaking.

> ✅ Al crear tu contexto, quedas **automáticamente vinculado** a él.
> Ya puedes usarlo para buscar actividades.

---

### Paso 3 — Busca o publica una actividad

**Buscar una actividad existente:**
```
/buscar-actividad
```
Opciones disponibles:
- `texto` — Describe qué tipo de actividad buscas en texto libre
  *(Ej: "busco trama de vampiros lenta, drama político")*
- `contexto` — Tu personaje/contexto para afinar la búsqueda

El bot responde con los 5 resultados más compatibles, ordenados por porcentaje de afinidad.

---

**Publicar tu propia búsqueda:**
```
/actividad crear contexto_principal:[tu contexto]
```
Opciones:
- `contexto_principal` — Tu contexto principal (autocomplete)
- `contexto_secundario` — Segundo contexto opcional

El bot abrirá un modal para añadir:
- **¿Qué estás buscando?** — Título corto de tu búsqueda
- **Contexto Extra** — Horarios, nivel, restricciones adicionales (opcional)

Tu actividad quedará publicada y visible para otros jugadores que usen `/buscar-actividad`.

---

### Paso 4 — Busca compañeros de comunidad (no actividades)

```
/buscar-partner
```

Busca **jugadores** compatibles contigo (no actividades publicadas), usando tu
firma semántica de perfil. Útil para formar equipos estables o encontrar
compañeros habituales.

---

## Ficha en texto libre

Si tu servidor soporta el formato de ficha MUDRAIS, puedes pegar tu ficha completa:

```
/ficha
```

El bot abrirá un área de texto donde puedes pegar tu ficha en formato libre.
La IA la analizará y extraerá automáticamente todos los campos relevantes.

---

## Tu estado actual

```
/status
```

Muestra tu estado actual: monedas, energía, perfil completado y estado del tutorial.

---

## Sistema de Vaults (mundos narrativos)

En servidores de roleplay, los **Vaults** son los mundos o settings narrativos.
Cada Vault tiene su propio canal y espacio para personajes y actividades.

**Crear un Vault:**
```
/create_vault archetype:[tipo de comunidad]
```

El bot creará automáticamente:
- Canal principal del Vault
- Foro de contextos (personajes, locaciones, etc.)
- Foro de actividades (partidas abiertas)

> Solo admins y Game Masters pueden crear Vaults. Los jugadores crean contextos
> y actividades dentro de los Vaults existentes.

---

## Comandos — Resumen rápido

| Comando | Para qué sirve |
|---------|---------------|
| `/registro` | Crear o editar tu perfil de jugador |
| `/ficha` | Pegar tu ficha en texto libre para que la IA la procese |
| `/create type:[tipo]` | Crear un contexto (personaje, libro, perfil gamer…) |
| `/actividad crear` | Publicar una búsqueda de actividad/partida |
| `/buscar-actividad` | Encontrar actividades compatibles en el servidor |
| `/buscar-partner` | Encontrar jugadores compatibles contigo |
| `/status` | Ver tu estado: monedas, energía, perfil |
| `/create_vault archetype:[tipo]` | Crear un mundo narrativo *(admins)* |

---

## Preguntas frecuentes

**¿En qué idioma debo escribir mi perfil?**
En el idioma que quieras. El bot traduce automáticamente los campos relevantes
al inglés para el motor de búsqueda. Tu carta de presentación se queda en tu idioma.

**¿Cuánto tardan los resultados de matchmaking?**
La búsqueda es prácticamente instantánea. El procesamiento inicial de tu perfil
(cuando te registras por primera vez) puede tardar unos segundos mientras la IA
analiza tu texto y genera el vector semántico.

**¿Por qué el porcentaje de compatibilidad no es 100% con alguien que parece perfecto?**
El motor compara muchas dimensiones a la vez — estilo, género, tempo, dinámica de rol.
Un 70%+ ya es una compatibilidad muy alta. El 100% es matemáticamente imposible
(requeriría que dos personas tengan exactamente el mismo vector).

**¿Puedo editar mi perfil?**
Sí, con `/registro` de nuevo. Puede tener un coste en monedas si ya completaste
el tutorial (precio de edición configurable por el servidor).

**¿Mis límites absolutos (líneas rojas) son visibles para otros jugadores?**
No. El sistema los usa internamente para filtrar resultados — nunca se muestran
en el perfil público. Solo los administradores del sistema pueden consultarlos.

**¿Qué pasa si no relleno todos los campos?**
El bot intenta completar los campos vacíos usando el contexto de lo que sí escribiste.
Pero cuantos más detalles des, más preciso será el matchmaking.

---

## Para administradores de servidor

Si eres admin de un servidor que quiere usar MUDRAIS:

1. **Invita el bot** a tu servidor (link del Developer Portal)
2. **Autorízate via OAuth** en el portal de MUDRAIS para obtener tu token de API
3. **Configura tu Vault** con `/create_vault`
4. **El archetype** (tipo de comunidad) ya debe estar configurado en el sistema
   Si quieres un archetype nuevo (ej: "Club de lectura de ciencia ficción"),
   contacta al equipo de MUDRAIS para configurarlo en el panel de administración

> Los archetypes se configuran en el panel Filament del sistema.
> Cada archetype define los campos del formulario, los prompts de IA
> y las reglas de matchmaking específicas de tu comunidad.

---

## Contacto y soporte

Para reportar problemas, sugerir mejoras o solicitar un nuevo archetype para tu servidor,
contacta al equipo de MUDRAIS a través del canal de soporte de tu servidor o
directamente con los administradores del sistema.
