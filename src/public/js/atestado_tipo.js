(function () {
    // Alterna o formulario de atestado entre "dia(s) inteiro(s)" e "parte do dia" (consulta/exame com volta no mesmo dia).
    // A validacao que vale e a do servidor; isto so mostra/esconde os campos e marca as horas como obrigatorias.
    var tipo = document.getElementById('tipo_atestado');
    var dias = document.getElementById('bloco-dias');
    var parcial = document.getElementById('bloco-parcial');
    if (!tipo || !dias || !parcial) {
        return;
    }
    var inicio = parcial.querySelector('[name="hora_inicio"]');
    var fim = parcial.querySelector('[name="hora_fim"]');
    function aplicar() {
        var ehParcial = tipo.value === 'parcial';
        dias.style.display = ehParcial ? 'none' : 'block';
        parcial.style.display = ehParcial ? 'block' : 'none';
        inicio.required = ehParcial;
        fim.required = ehParcial;
    }
    tipo.addEventListener('change', aplicar);
    aplicar();
})();
