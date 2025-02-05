jQuery(function ($) { 
    const licenses = []; // Start with an empty array  
    const user_id = $('#user_id').val();  
    // Function to update licenses on the server
    function updateLicenses(data)
    { 
        $.ajax({
            url: licenseData.ajax_url,
            method: 'POST',
            data: data,
            success: function (response)
            {
                if (response.success)
                {  
                                       
                    alert(response.data.message);   
                    if (data.function_action!== 'delete')
                    {

                    // After adding the license, show the manage license row
                    if (data.licenses.length > 0) {
                        $('#manage-license-row').show();
                    }

                     
                        $('#license').append(
                            $('<option>', {
                                value: data.licenses,
                                text: data.licenses,
                            })
                        ); 
                        if(data.licenses_to_edit!=="")
                        {
                        $('#license').find(`option[value="${data.licenses_to_edit}"]`).remove(); 
                        }
                         
                    }
                    else
                    {
                        $('#license').find(`option[value="${data.licenses}"]`).remove();
                    
                    } 
                }
                else
                {
                        alert(response.data.message); 
                }
                formclearVal();
                   
            },
            error: function () {
            alert('An error occurred while processing the request.');
            },
        });
   }
    
   // Handle Add/Edit license
   $('#add-update-license').on('click', function ()
   {
        const newLicense = $('#new_license').val().trim();
        // Define a regular expression to allow only letters, numbers, dashes (-), and underscores (_)
        const isValidLicense = /^[a-zA-Z0-9\-_]+$/.test(newLicense);
       
        if (!newLicense) {
        alert('License number cannot be empty.');
        } else if (!isValidLicense) {
        alert('License number contains invalid characters. Only letters, numbers, dashes (-), and underscores (_) are allowed.');
        }  
        else
        {
        const data = {
        action: 'update_user_licenses',
        nonce: licenseData.nonce,
        licenses_to_edit:  $('#license').val(), // Old license to be edited
        licenses: [newLicense],
        user_id: user_id,
        };
        updateLicenses(data); // Send to the server
        }
    });
    // Delete License
    $('#delete-license').on('click', function ()
    {
        const selectedLicense = $('#license').val();  // Get the selected license
        const newLicense = $('#new_license').val().trim();
        // Define a regular expression to allow only letters, numbers, dashes (-), and underscores (_)
        const isValidLicense = /^[a-zA-Z0-9\-_]+$/.test(newLicense);
       
        if (!newLicense && !selectedLicense)
         {
            alert('License number cannot be empty.');
        }
        else if (!isValidLicense)
        {
        alert('License number contains invalid characters. Only letters, numbers, dashes (-), and underscores (_) are allowed.');
        }
        else
        {
            if (selectedLicense && confirm('Are you sure you want to delete this license?')) {
                const data = {
                    action: 'update_user_licenses',
                    nonce: licenseData.nonce,
                    licenses: [selectedLicense],  // Pass license in an array
                    user_id: user_id,
                    function_action: 'delete',  // Set the action to "delete"
                };
                updateLicenses(data);  // Update the server with the delete action 
                resetForm('none');
            }
     }
    });

    // Handle Cancel edit
    $('#remove-edit-section').on('click', function () {
        resetForm('none');  // Reset the form to default "Add License" state
        formclearVal();
    });

    // Handle license selection from the dropdown
    $('#license').on('change', function () {
        const selectedLicense = $(this).val();  // Get the selected value
        if (selectedLicense) {
            $('#new_license').val(selectedLicense);
            resetForm('inline-block');  // Show edit buttons
        }
        else
        {
            formclearVal();
        }
    });  

    // Reset form to default "Add License" state
    function resetForm(status) { 
        $('#delete-license, #remove-edit-section').css('display', status);  // Toggle visibility of buttons
    }
    function formclearVal()
    {
        $('#new_license').val(''); // Clear input field
        $('#license').val(''); // Reset dropdown 
    }
});

