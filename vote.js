function lockVote(groupName, button) {
    const radios = document.querySelectorAll(`input[name="${groupName}"]`);
    let selected = false;

    radios.forEach(radio => {
        if (radio.checked) {
            selected = true;
            radio.disabled = true;
        } else {
            radio.disabled = true;
        }
    });

    if (selected) {
        button.disabled = true;
        alert("Your vote has been submitted for this position!");
    } else {
        alert("Please select a candidate before voting.");
    }
}