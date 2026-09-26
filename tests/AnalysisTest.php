<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Analysis.php';

/**
 * The budget is read from the request and not from the person, which puts two things at
 * stake: that a figure left in a box cannot reach the prompt when nobody is shopping, and
 * that when it does reach it, it reaches the fingerprint too -- otherwise asking again with
 * a different budget would hand back the answer to the previous one.
 */

describe('analysis_budget()');

test('con compras activadas se envía lo que la persona escribió', function () {
    same('Hasta 120 €', analysis_budget(['shopping' => true, 'budget' => 'Hasta 120 €']));
});

test('se limpia por los extremos', function () {
    same('120 €', analysis_budget(['shopping' => true, 'budget' => "  120 €\n"]));
});

test('sin compras no se envía nada, aunque el campo traiga algo', function () {
    // El campo se esconde al desmarcar el interruptor, pero el valor sigue en el formulario:
    // si llegara al prompt, estaríamos pagando por una condición que nadie ha pedido.
    same('', analysis_budget(['shopping' => false, 'budget' => 'Hasta 120 €']));
    same('', analysis_budget(['budget' => 'Hasta 120 €']));
});

test('con compras pero sin presupuesto, vacío', function () {
    same('', analysis_budget(['shopping' => true]));
    same('', analysis_budget(['shopping' => true, 'budget' => '   ']));
});

test('se recorta: es una cifra y una palabra, no un párrafo', function () {
    same(ANALYSIS_BUDGET_MAX_LENGTH, mb_strlen(analysis_budget(['shopping' => true, 'budget' => str_repeat('9', ANALYSIS_BUDGET_MAX_LENGTH + 40)])));
});

test('un presupuesto que no es texto no revienta', function () {
    same('', analysis_budget(['shopping' => true, 'budget' => null]));
});

describe('analysis_fingerprint() · el presupuesto forma parte de la pregunta');

$garments = [['id' => 1, 'name' => 'Camisa'], ['id' => 2, 'name' => 'Pantalón']];

test('dos peticiones iguales dan la misma huella', function () use ($garments) {
    $input = ['preferences' => ['style' => 'Minimalista'], 'shopping' => true, 'budget' => '120 €'];
    same(analysis_fingerprint($garments, $input), analysis_fingerprint($garments, $input));
});

test('cambiar el presupuesto cambia la huella', function () use ($garments) {
    // Si no, pedir lo mismo con otro presupuesto devolvería la respuesta del anterior.
    $barato = analysis_fingerprint($garments, ['preferences' => [], 'shopping' => true, 'budget' => '50 €']);
    $caro = analysis_fingerprint($garments, ['preferences' => [], 'shopping' => true, 'budget' => '300 €']);
    truthy($barato !== $caro);
});

test('sin compras, el presupuesto no puede cambiar la huella', function () use ($garments) {
    // analysis_budget() lo vacía antes, así que dos análisis que solo se diferencian en un
    // número que nadie va a usar son el mismo análisis y no se pagan dos veces.
    $uno = ['preferences' => [], 'shopping' => false, 'budget' => analysis_budget(['shopping' => false, 'budget' => '50 €'])];
    $otro = ['preferences' => [], 'shopping' => false, 'budget' => analysis_budget(['shopping' => false, 'budget' => '300 €'])];
    same(analysis_fingerprint($garments, $uno), analysis_fingerprint($garments, $otro));
});

describe('el perfil ya no guarda presupuesto');

test('un presupuesto enviado al perfil se descarta', function () {
    falsy(isset(style_profile(['style' => 'Minimalista', 'budget' => 'Hasta 120 €'])['budget']));
});

test('y tampoco sobrevive en un perfil ya guardado', function () {
    falsy(isset(style_profile_update([], ['budget' => 'Hasta 120 €', 'style' => 'Minimalista'])['budget']));
});
