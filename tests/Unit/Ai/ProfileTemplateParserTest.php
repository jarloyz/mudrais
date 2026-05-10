<?php

namespace Tests\Unit\Ai;

use App\Infrastructure\Ai\Parsers\ProfileTemplateParser;
use Tests\TestCase;

class ProfileTemplateParserTest extends TestCase
{
    private ProfileTemplateParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ProfileTemplateParser();
    }

    private function fullFicha(): string
    {
        return <<<FICHA
        **DATOS BÁSICOS**
        * Edad: 28
        * Nacionalidad: México
        * Experiencia: Veterano

        **LOGÍSTICA Y ESTILO**
        * Horarios disponibles: Lunes a viernes 21:00-00:00 UTC-6
        * Extensión: Alta/Biblias
        * Líneas Rojas: gore, arañas, abuso infantil

        **TUS AFINIDADES**
        1. Fantasía épica
        2. Misterio sobrenatural
        3. Horror cósmico

        **ESTILO NARRATIVO**
        Llevo 6 años haciendo rol narrativo. Me especializo en personajes con arcos complejos.
        FICHA;
    }

    public function test_parses_age(): void
    {
        $result = $this->parser->parse($this->fullFicha());
        $this->assertSame(28, $result['age']);
    }

    public function test_parses_nationality(): void
    {
        $result = $this->parser->parse($this->fullFicha());
        $this->assertSame('México', $result['nationality']);
    }

    public function test_normalizes_experience_to_veteran(): void
    {
        $result = $this->parser->parse($this->fullFicha());
        $this->assertSame('Veteran', $result['experience_level']);
    }

    public function test_parses_all_red_lines(): void
    {
        $result = $this->parser->parse($this->fullFicha());
        $this->assertSame(['gore', 'arañas', 'abuso infantil'], $result['red_lines']);
    }

    public function test_parses_affinities_in_order(): void
    {
        $result = $this->parser->parse($this->fullFicha());
        $this->assertSame(['Fantasía épica', 'Misterio sobrenatural', 'Horror cósmico'], $result['affinities']);
    }

    public function test_parses_schedule_with_timezone(): void
    {
        $result = $this->parser->parse($this->fullFicha());
        $this->assertSame('UTC-6', $result['schedule']['timezone']);
        $this->assertStringContainsString('21:00', $result['schedule']['description']);
    }

    public function test_parses_verbosity(): void
    {
        $result = $this->parser->parse($this->fullFicha());
        $this->assertSame('Alta/Biblias', $result['verbosity']);
    }

    public function test_parses_raw_profile(): void
    {
        $result = $this->parser->parse($this->fullFicha());
        $this->assertStringContainsString('6 años', $result['raw_profile']);
    }

    public function test_is_complete_returns_true_for_full_ficha(): void
    {
        $result = $this->parser->parse($this->fullFicha());
        $this->assertTrue($this->parser->isComplete($result));
    }

    public function test_is_complete_returns_false_when_red_lines_missing(): void
    {
        $ficha = str_replace('* Líneas Rojas: gore, arañas, abuso infantil', '', $this->fullFicha());
        $result = $this->parser->parse($ficha);
        $this->assertFalse($this->parser->isComplete($result));
    }

    public function test_red_lines_split_by_semicolon(): void
    {
        $ficha = str_replace('gore, arañas, abuso infantil', 'gore; violencia explícita', $this->fullFicha());
        $result = $this->parser->parse($ficha);
        $this->assertSame(['gore', 'violencia explícita'], $result['red_lines']);
    }

    public function test_red_lines_split_by_y(): void
    {
        $ficha = str_replace('gore, arañas, abuso infantil', 'gore y arañas', $this->fullFicha());
        $result = $this->parser->parse($ficha);
        $this->assertSame(['gore', 'arañas'], $result['red_lines']);
    }

    public function test_experience_normalizes_master(): void
    {
        $ficha = str_replace('Experiencia: Veterano', 'Experiencia: Máster', $this->fullFicha());
        $result = $this->parser->parse($ficha);
        $this->assertSame('Master', $result['experience_level']);
    }

    public function test_experience_normalizes_unknown_to_novice(): void
    {
        $ficha = str_replace('Experiencia: Veterano', 'Experiencia: Intermedio', $this->fullFicha());
        $result = $this->parser->parse($ficha);
        $this->assertSame('Novice', $result['experience_level']);
    }

    public function test_returns_nulls_for_empty_input(): void
    {
        $result = $this->parser->parse('');
        $this->assertNull($result['age']);
        $this->assertNull($result['red_lines']);
        $this->assertFalse($this->parser->isComplete($result));
    }
}
