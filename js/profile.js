$(document).ready(function() {
    const token = localStorage.getItem('token');
    
    if(!token) {
       window.location.href = 'login.html';
        return;
    }

    // Fetch Profile data via AJAX
    $.ajax({
        url: 'php/profile.php',
        type: 'GET',
        headers: { 'Authorization': 'Bearer ' + token },
        success: function(response) {
            if(response.status === 'success') {
                $('#name').val(response.data.name);
                $('#email').val(response.data.email);
                $('#age').val(response.data.age);
                $('#dob').val(response.data.dob);
                $('#contact').val(response.data.contact);
            } else {
                localStorage.clear();
               window.location.href = 'login.html';
            }
        },
        error: function() {
            localStorage.clear();
            window.location.href = 'login.html';
        }
    });

    // Update Profile submission
    $('#profileForm').on('submit', function(e) {
        e.preventDefault();

        const profileData = {
            age: $('#age').val(),
            dob: $('#dob').val(),
            contact: $('#contact').val()
        };

        $.ajax({
            url: 'php/profile.php',
            type: 'POST',
            headers: { 'Authorization': 'Bearer ' + token },
            contentType: 'application/json',
            data: JSON.stringify(profileData),
            success: function(response) {
                if(response.status === 'success') {
                    $('#alert-box').html('<div class="alert alert-success">Profile updated successfully!</div>');
                } else {
                    $('#alert-box').html(`<div class="alert alert-danger">${response.message}</div>`);
                }
            },
            error: function() {
                $('#alert-box').html('<div class="alert alert-danger">Failed to update profile.</div>');
            }
        });
    });

    // Logout handling
    $('#logoutBtn').on('click', function() {
        localStorage.clear();
        window.location.href = 'login.html';
    });
});