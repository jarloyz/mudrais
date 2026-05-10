# MUDRAIS — Guía del Lector y Escritor

Bienvenido a **MUDRAIS** (Modular Universal Dynamic Readership AI System).
Esta guía explica cómo funciona el sistema para ayudarte a encontrar **coautores, compañeros de escritura y proyectos de libros** basados en tus gustos reales.

No usa un sistema de filtros manuales. Usa vectores semánticos — la misma tecnología que los motores de búsqueda modernos — para entender tu estilo de escritura/lectura, tus géneros favoritos y lo que buscas, comparándolo con todos los demás miembros del servidor.

---

## ¿Para qué sirve?

- Encontrar un **coautor** que encaje con tu estilo de escritura e intereses para un proyecto literario.
- Buscar un **libro** específico dentro de la comunidad que encaje con tus intereses.
- Publicar tu propia búsqueda de coautor para tu libro y dejar que el sistema te traiga a los escritores más afines.

---

## ¿Cómo funciona? (versión simple)

1. **Te registras** — Le dices al bot qué tipo de lector/escritor eres. El bot lo analiza con IA y crea tu "firma literaria".
2. **Creas un libro** — Añades un **libro** (tu proyecto actual o el que te interesa). *Por ahora, el libro es el único tipo de contexto disponible.*
3. **Buscas un libro o un coautor** — Puedes usar el comando de búsqueda para encontrar libros en la comunidad, o publicar una "actividad" para buscar un coautor compatible contigo.
4. **El motor hace el match** — MUDRAIS compara gustos y devuelve los resultados más compatibles, con un porcentaje de afinidad.

---

## Primeros pasos

### Paso 1 — Regístrate

```
/registro
```

El bot abrirá un formulario de dos pasos:

**Paso 1/2 (Datos básicos):**
- Tu edad y nacionalidad.
- **Nivel de lectura/escritura:** (ej. lento, moderado, rápido).
- **Formatos preferidos:** (ej. Físico, Ebook, Audiobook).
- **¿Eres escritor?:** Marca esta opción si buscas colaborar en la creación de historias.

**Paso 2/2 (Perfil Semántico):**
- **Límites absolutos** — Temas o triggers que odias (Ej: "muerte de mascotas").
- **Tus favoritos** — Géneros, autores y tropos que te encantan.
- **Tu estilo** — Cómo te gusta escribir o debatir historias.

---

### Paso 2 — Crea tu libro (Contexto)

```
/create type:libro
```

Actualmente, **libro** es el único tipo de entidad que puedes crear. Aquí definirás:
- Título y autor.
- **URL del libro:** Enlace a Amazon, AO3, Wattpad o Goodreads.
- **Tipo de publicación:** (Fanfic, Web Serial, Literatura Clásica, Contemporáneo, Tradicional, Indie, Físico).

Rellena estos datos para que el motor pueda conectar tu obra con otros usuarios.

---

### Paso 3 — Busca Libros o Coautores

**Buscar un libro o proyecto:**
```
/search objetivo:libro
```
Puedes añadir la opción `texto` para afinar la búsqueda (Ej: `/search objetivo:libro texto:fantasía urbana oscura`). El sistema encontrará los libros que mejor encajan con tu perfil.

**Buscar un coautor:**
```
/search objetivo:coautor
```
Encuentra búsquedas de coautoría publicadas por otros escritores que sean compatibles con tu estilo.

---

### Paso 4 — Publica tu búsqueda de coautor (Actividad)

Si tienes un libro creado y quieres encontrar a alguien con quien trabajar:

```
/actividad crear contexto_principal:[tu libro]
```

En el formulario indicarás:
- **Tipo de proyecto:** (Novela, Relatos cortos, Poesía, Guion, No-ficción, Fanfiction).
- **Estilo de colaboración:** (Co-autoría igualitaria, Líder + contribuyente, Compañero de escritura/Accountability, Intercambio de Beta Reading).
- **Nivel de compromiso:** (Casual, Regular, Intensivo).
- **Género y Temas:** Descripción libre de lo que buscas en tu compañero.

---

## Otros comandos útiles

| Comando | Para qué sirve |
|---------|---------------|
| `/status` | Ver tu estado, nivel literario y energía |
| `/buscar-partner` | Encontrar lectores/escritores afines a tus gustos generales |
| `/ficha` | Pegar tu perfil completo en texto libre para que la IA lo procese |
| `/create_vault` | Crear un nuevo espacio literario o biblioteca *(admins)* |

---

## Preguntas frecuentes

**¿En qué idioma debo escribir?**
En el idioma que prefieras. La IA traduce internamente al inglés para el motor de búsqueda, pero tus textos públicos se mantienen en tu idioma original.

**¿Qué es la afinidad?**
Es un porcentaje basado en vectores semánticos. Un 70% o más indica una gran compatibilidad en estilo, temas y preferencias.
